<?php

namespace App\Services;

/** Descriptive momentum over observed snapshots, not verified consecutive exchange sessions. */
final class IdxMomentumIntelligence
{
    public const WINDOWS = [5,20,60,120,250];

    public function analyze(iterable $sessions): array
    {
        $observed = [];
        $engine = app(IdxSessionIntelligence::class);
        foreach ($sessions as $session) {
            if (count($observed) >= 250) {
                throw new \InvalidArgumentException('II_IDX_MOMENTUM_LIMIT');
            }
            $summary = $engine->summarize($session['rows']);
            $date = \DateTimeImmutable::createFromFormat('!d M Y',$summary['session'])->format('Y-m-d');
            if (isset($observed[$date]) || $date >= now('Asia/Jakarta')->toDateString()) {
                throw new \InvalidArgumentException('II_IDX_MOMENTUM_DATE_INVALID');
            }
            $b = $summary['breadth'];
            $eligible = $b['up']+$b['down']+$b['flat'];
            $prices = [];
            foreach ($session['rows'] as $row) {
                if ($engine->summarize([$row])['breadth']['excluded'] === 0) {
                    $prices[$row['Stock Code']] = [(float)$row['Close'], (float)$row['Volume']];
                }
            }
            $observed[$date] = ['date'=>$date,'sha256'=>$session['sha256'] ?? null,'universe'=>$summary['universe'],
                'eligible'=>$eligible,'up'=>$b['up'],'down'=>$b['down'],'flat'=>$b['flat'],
                'up_pct'=>$eligible ? 100*$b['up']/$eligible : null,
                'net_breadth_pct'=>$eligible ? 100*($b['up']-$b['down'])/$eligible : null,'prices'=>$prices];
        }
        ksort($observed);
        $ordered = array_values($observed);
        $windows = [];
        foreach (self::WINDOWS as $n) {
            $windows[] = count($ordered) < $n
                ? ['window'=>$n,'status'=>'INSUFFICIENT_HISTORY','available'=>count($ordered),'required'=>$n]
                : $this->window(array_slice($ordered,-$n));
        }
        return ['available'=>count($ordered),'windows'=>$windows,'sessions'=>array_map(static function ($s) {
            unset($s['prices']);
            return $s;
        },$ordered),'calendar_verified'=>false,'admitted'=>false,'trading_authorized'=>false];
    }

    private function window(array $sessions): array
    {
        $n = count($sessions);
        $first = $sessions[0];
        $last = $sessions[$n-1];
        $matched = $first['prices'];
        foreach ($sessions as $s) {
            $matched = array_intersect_key($matched,$s['prices']);
        }
        $result = ['window'=>$n,'status'=>$matched ? 'OBSERVED_ONLY' : 'NO_MATCHED_COHORT',
            'available'=>$n,'required'=>$n,'first_date'=>$first['date'],'last_date'=>$last['date'],
            'matched_count'=>count($matched),'median_change_pct'=>null,'breadth_change_pp'=>null,
            'leaders'=>[],'laggards'=>[]];
        if ($first['net_breadth_pct'] !== null && $last['net_breadth_pct'] !== null) {
            $result['breadth_change_pp'] = $last['net_breadth_pct']-$first['net_breadth_pct'];
        }
        if (!$matched) {
            return $result;
        }
        $moves = [];
        foreach ($matched as $ticker=>$initial) {
            $up = 0;
            $down = 0;
            $volumes = 0.0;
            for ($i=1; $i<$n; $i++) {
                $previous = $sessions[$i-1]['prices'][$ticker][0];
                $current = $sessions[$i]['prices'][$ticker][0];
                $up += (int)($current>$previous);
                $down += (int)($current<$previous);
                $volumes += $sessions[$i-1]['prices'][$ticker][1];
            }
            $mid = intdiv($n-1,2);
            $middle = $sessions[$mid]['prices'][$ticker][0];
            $end = $last['prices'][$ticker][0];
            $moves[] = ['ticker'=>(string)$ticker,'price_change_pct'=>100*($end/$initial[0]-1),
                'up_transition_pct'=>100*$up/($n-1),'down_transition_pct'=>100*$down/($n-1),
                'raw_volume_ratio'=>$last['prices'][$ticker][1]/($volumes/($n-1)),
                'acceleration_pp_per_transition'=>100*(($end/$middle-1)/($n-1-$mid)-($middle/$initial[0]-1)/$mid)];
        }
        usort($moves,fn($a,$b)=>($b['price_change_pct']<=>$a['price_change_pct']) ?: strcmp($a['ticker'],$b['ticker']));
        $count = count($moves);
        $mid = intdiv($count,2);
        $median = $count%2 ? $moves[$mid]['price_change_pct'] : ($moves[$mid-1]['price_change_pct']+$moves[$mid]['price_change_pct'])/2;
        foreach ($moves as &$move) {
            $move['relative_to_median_pp'] = $move['price_change_pct']-$median;
        }
        unset($move);
        $result['median_change_pct'] = $median;
        $result['leaders'] = array_slice($moves,0,10);
        $result['laggards'] = array_slice(array_reverse($moves),0,10);
        return $result;
    }
}
