package us.mobasheri.health

import androidx.activity.result.ActivityResult
import androidx.health.connect.client.HealthConnectClient
import androidx.health.connect.client.PermissionController
import androidx.health.connect.client.records.BloodGlucoseRecord
import androidx.health.connect.client.records.BloodPressureRecord
import androidx.health.connect.client.records.BodyFatRecord
import androidx.health.connect.client.records.HeartRateRecord
import androidx.health.connect.client.records.OxygenSaturationRecord
import androidx.health.connect.client.records.RestingHeartRateRecord
import androidx.health.connect.client.records.SleepSessionRecord
import androidx.health.connect.client.records.WeightRecord
import androidx.health.connect.client.request.ReadRecordsRequest
import androidx.health.connect.client.time.TimeRangeFilter
import com.getcapacitor.JSArray
import com.getcapacitor.JSObject
import com.getcapacitor.Plugin
import com.getcapacitor.PluginCall
import com.getcapacitor.PluginMethod
import com.getcapacitor.annotation.ActivityCallback
import com.getcapacitor.annotation.CapacitorPlugin
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import java.time.Instant
import java.time.LocalDate
import java.time.ZoneId
import kotlin.math.roundToInt

/**
 * Reads the Health Connect record types the third-party capacitor-health
 * plugin doesn't expose: sleep, heart rate, resting heart rate, weight,
 * body fat, blood pressure, blood glucose and oxygen saturation.
 *
 * Steps / distance / active-calories / workouts stay with capacitor-health —
 * they already work, so there's no reason to reimplement them here.
 *
 * Everything is bucketed by LOCAL calendar day, matching how the web app
 * stores a day ("YYYY-MM-DD"), so values land on the day the user actually
 * lived them rather than a UTC day.
 */
@CapacitorPlugin(name = "ThriveHealth")
class ThriveHealthPlugin : Plugin() {

    private val zone: ZoneId get() = ZoneId.systemDefault()

    private val client: HealthConnectClient by lazy {
        HealthConnectClient.getOrCreate(context)
    }

    /** Permissions this plugin needs, as Health Connect permission strings. */
    private val readPermissions = setOf(
        "android.permission.health.READ_SLEEP",
        "android.permission.health.READ_HEART_RATE",
        "android.permission.health.READ_RESTING_HEART_RATE",
        "android.permission.health.READ_WEIGHT",
        "android.permission.health.READ_BODY_FAT",
        "android.permission.health.READ_BLOOD_PRESSURE",
        "android.permission.health.READ_BLOOD_GLUCOSE",
        "android.permission.health.READ_OXYGEN_SATURATION"
    )

    private fun dayOf(instant: Instant): String =
        LocalDate.ofInstant(instant, zone).toString()

    /** Which of our permissions are currently granted.
     *  (Named to avoid colliding with Plugin.checkPermissions.) */
    @PluginMethod
    fun grantedHealthPermissions(call: PluginCall) {
        CoroutineScope(Dispatchers.IO).launch {
            try {
                val granted = client.permissionController.getGrantedPermissions()
                val out = JSObject()
                for (p in readPermissions) {
                    out.put(p.substringAfterLast('.'), granted.contains(p))
                }
                val res = JSObject()
                res.put("permissions", out)
                call.resolve(res)
            } catch (e: Exception) {
                call.reject("Permission check failed: ${e.message}")
            }
        }
    }

    /** Ask the user to grant our read permissions (Health Connect's own UI). */
    @PluginMethod
    fun requestHealthPermissions(call: PluginCall) {
        try {
            val contract = PermissionController.createRequestPermissionResultContract()
            val intent = contract.createIntent(context, readPermissions)
            startActivityForResult(call, intent, "onPermissionResult")
        } catch (e: Exception) {
            call.reject("Permission request failed: ${e.message}")
        }
    }

    @ActivityCallback
    private fun onPermissionResult(call: PluginCall?, result: ActivityResult) {
        if (call == null) return
        // Report the now-granted set (the contract's parsed result only covers
        // what the user just acted on; the controller is the source of truth).
        CoroutineScope(Dispatchers.IO).launch {
            try {
                val granted = client.permissionController.getGrantedPermissions()
                val out = JSObject()
                for (p in readPermissions) out.put(p.substringAfterLast('.'), granted.contains(p))
                val res = JSObject()
                res.put("permissions", out)
                call.resolve(res)
            } catch (e: Exception) {
                call.reject("Permission result failed: ${e.message}")
            }
        }
    }

    /**
     * Read everything in one round-trip. Returns, per metric, an array of
     * { date, value } (blood pressure also carries value2 = diastolic).
     * A metric the user hasn't granted simply comes back empty rather than
     * failing the whole sync.
     */
    @PluginMethod
    fun queryAll(call: PluginCall) {
        val startDate = call.getString("startDate")
        val endDate = call.getString("endDate")
        if (startDate == null || endDate == null) {
            call.reject("Missing required parameters: startDate or endDate")
            return
        }

        CoroutineScope(Dispatchers.IO).launch {
            try {
                val range = TimeRangeFilter.between(Instant.parse(startDate), Instant.parse(endDate))
                val granted = client.permissionController.getGrantedPermissions()
                fun has(name: String) = granted.contains("android.permission.health.$name")

                val result = JSObject()

                // ── Sleep: total hours asleep per day, keyed by the day the
                // session ENDED (waking up Tuesday = Tuesday's sleep).
                if (has("READ_SLEEP")) {
                    val totals = HashMap<String, Double>()
                    for (r in client.readRecords(ReadRecordsRequest(SleepSessionRecord::class, range)).records) {
                        val hours = (r.endTime.epochSecond - r.startTime.epochSecond) / 3600.0
                        if (hours <= 0) continue
                        val key = dayOf(r.endTime)
                        totals[key] = (totals[key] ?: 0.0) + hours
                    }
                    result.put("sleep", totals.toRows { round1(it) })
                }

                // ── Resting heart rate: average of the day's readings.
                if (has("READ_RESTING_HEART_RATE")) {
                    val sums = HashMap<String, Pair<Double, Int>>()
                    for (r in client.readRecords(ReadRecordsRequest(RestingHeartRateRecord::class, range)).records) {
                        val key = dayOf(r.time)
                        val (s, c) = sums[key] ?: (0.0 to 0)
                        sums[key] = (s + r.beatsPerMinute) to (c + 1)
                    }
                    result.put("rhr", sums.toAvgRows())
                }

                // ── Heart rate: average across all samples in the day.
                if (has("READ_HEART_RATE")) {
                    val sums = HashMap<String, Pair<Double, Int>>()
                    for (r in client.readRecords(ReadRecordsRequest(HeartRateRecord::class, range)).records) {
                        for (s in r.samples) {
                            val key = dayOf(s.time)
                            val (sum, c) = sums[key] ?: (0.0 to 0)
                            sums[key] = (sum + s.beatsPerMinute) to (c + 1)
                        }
                    }
                    result.put("hr", sums.toAvgRows())
                }

                // ── Weight: last reading of the day.
                if (has("READ_WEIGHT")) {
                    val last = HashMap<String, Pair<Instant, Double>>()
                    for (r in client.readRecords(ReadRecordsRequest(WeightRecord::class, range)).records) {
                        val key = dayOf(r.time)
                        val prev = last[key]
                        if (prev == null || r.time.isAfter(prev.first)) last[key] = r.time to r.weight.inKilograms
                    }
                    result.put("weight", last.toLastRows { round1(it) })
                }

                // ── Body fat %: last reading of the day.
                if (has("READ_BODY_FAT")) {
                    val last = HashMap<String, Pair<Instant, Double>>()
                    for (r in client.readRecords(ReadRecordsRequest(BodyFatRecord::class, range)).records) {
                        val key = dayOf(r.time)
                        val prev = last[key]
                        if (prev == null || r.time.isAfter(prev.first)) last[key] = r.time to r.percentage.value
                    }
                    result.put("bodyfat", last.toLastRows { round1(it) })
                }

                // ── Blood pressure: last reading of the day (systolic/diastolic).
                if (has("READ_BLOOD_PRESSURE")) {
                    val last = HashMap<String, Triple<Instant, Double, Double>>()
                    for (r in client.readRecords(ReadRecordsRequest(BloodPressureRecord::class, range)).records) {
                        val key = dayOf(r.time)
                        val prev = last[key]
                        if (prev == null || r.time.isAfter(prev.first)) {
                            last[key] = Triple(r.time, r.systolic.inMillimetersOfMercury, r.diastolic.inMillimetersOfMercury)
                        }
                    }
                    val arr = JSArray()
                    for ((date, v) in last) {
                        val o = JSObject()
                        o.put("date", date)
                        o.put("value", v.second.roundToInt())
                        o.put("value2", v.third.roundToInt())
                        arr.put(o)
                    }
                    result.put("bp", arr)
                }

                // ── Blood glucose: average of the day's readings (mg/dL).
                if (has("READ_BLOOD_GLUCOSE")) {
                    val sums = HashMap<String, Pair<Double, Int>>()
                    for (r in client.readRecords(ReadRecordsRequest(BloodGlucoseRecord::class, range)).records) {
                        val key = dayOf(r.time)
                        val (s, c) = sums[key] ?: (0.0 to 0)
                        sums[key] = (s + r.level.inMilligramsPerDeciliter) to (c + 1)
                    }
                    result.put("glucose", sums.toAvgRows())
                }

                // ── Oxygen saturation: average % for the day.
                if (has("READ_OXYGEN_SATURATION")) {
                    val sums = HashMap<String, Pair<Double, Int>>()
                    for (r in client.readRecords(ReadRecordsRequest(OxygenSaturationRecord::class, range)).records) {
                        val key = dayOf(r.time)
                        val (s, c) = sums[key] ?: (0.0 to 0)
                        sums[key] = (s + r.percentage.value) to (c + 1)
                    }
                    result.put("spo2", sums.toAvgRows())
                }

                call.resolve(result)
            } catch (e: Exception) {
                call.reject("Health query failed: ${e.message}")
            }
        }
    }

    private fun round1(v: Double): Double = Math.round(v * 10.0) / 10.0

    private fun HashMap<String, Double>.toRows(transform: (Double) -> Double): JSArray {
        val arr = JSArray()
        for ((date, v) in this) {
            if (v <= 0) continue
            val o = JSObject()
            o.put("date", date)
            o.put("value", transform(v))
            arr.put(o)
        }
        return arr
    }

    private fun HashMap<String, Pair<Double, Int>>.toAvgRows(): JSArray {
        val arr = JSArray()
        for ((date, v) in this) {
            if (v.second <= 0) continue
            val o = JSObject()
            o.put("date", date)
            o.put("value", (v.first / v.second).roundToInt())
            arr.put(o)
        }
        return arr
    }

    private fun HashMap<String, Pair<Instant, Double>>.toLastRows(transform: (Double) -> Double): JSArray {
        val arr = JSArray()
        for ((date, v) in this) {
            if (v.second <= 0) continue
            val o = JSObject()
            o.put("date", date)
            o.put("value", transform(v.second))
            arr.put(o)
        }
        return arr
    }
}
