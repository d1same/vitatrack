<?php
// Health calculations: BMR, TDEE, calorie & macro targets.

function compute_targets(array $p): array {
    $age    = max(14, (int)date('Y') - (int)($p['birth_year'] ?: 1980));
    $weight = (float)($p['start_weight_kg'] ?: 80);
    $height = (float)($p['height_cm'] ?: 170);
    $male   = ($p['sex'] ?? 'male') === 'male';

    // Mifflin-St Jeor
    $bmr  = 10 * $weight + 6.25 * $height - 5 * $age + ($male ? 5 : -161);
    $tdee = $bmr * (float)($p['activity'] ?: 1.375);

    // 0.5 kg/week ≈ 550 kcal/day deficit
    $deficit = 1100 * (float)($p['weekly_rate'] ?: 0.5);
    $kcal = max($male ? 1500 : 1200, round($tdee - $deficit));

    $goal = (float)($p['goal_weight_kg'] ?: $weight);
    $diet = $p['diet'] ?? 'keto';
    if ($diet === 'keto') {
        $carbs   = 25;
        $protein = round(1.6 * $goal);
        $fat     = max(30, round(($kcal - $carbs * 4 - $protein * 4) / 9));
    } elseif ($diet === 'lowcarb') {
        $carbs   = 100;
        $protein = round(1.6 * $goal);
        $fat     = max(30, round(($kcal - $carbs * 4 - $protein * 4) / 9));
    } else { // balanced 40/30/30
        $carbs   = round($kcal * 0.40 / 4);
        $protein = round($kcal * 0.30 / 4);
        $fat     = round($kcal * 0.30 / 9);
    }

    return [
        'bmr'        => round($bmr),
        'tdee'       => round($tdee),
        'kcal_target'=> $kcal,
        'protein_g'  => $protein,
        'carbs_g'    => $carbs,
        'fat_g'      => $fat,
        'water_ml'   => min(4000, max(1800, round($weight * 35 / 100) * 100)),
        'bmi'        => round($weight / pow($height / 100, 2), 1),
    ];
}
