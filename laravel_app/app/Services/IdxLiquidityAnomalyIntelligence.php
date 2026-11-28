<?php

namespace App\Services;

/** Research-only activity proxies. Raw source units/scope are not verified liquidity. */
final class IdxLiquidityAnomalyIntelligence
{
    public const BASELINE = 20;
    public const THRESHOLD = 3.5;

    public function analyze(iterable $sessions): array
    {
        $observed = [];
        $engine = app(IdxSessionIntelligence::class);
        foreach ($sessions as $session) {
            if (count($observed) >= self::BASELINE+1) {
                throw new \InvalidArgumentException('II_IDX_ACTIVITY_LIMIT');
            }
            $summary = $engine->summarize($session['rows']);
            $date = \DateTimeImmutable::createFromFormat('!d M Y',$summary['session'])->format('Y-m-d');
            if (isset($observed[$date]) || $date >= now('Asia/Jakarta')->toDateString()) {
                throw new \InvalidArgumentException('II_IDX_ACTIVITY_DATE_INVALID');
            }
            $rows = [];
            foreach ($session['rows'] as $row) {
                if ($engine->summarize([$row])['breadth']['excluded'] !== 0) {
                    continue;
                }
                $fields = ['volume'=>(float)$row['Volume']];
                foreach (['value'=>'Value','frequency'=>'Frequency'] as $key=>$source) {
                    $v = $row[$source] ?? null;
                    $fields[$key] = is_string($v) && preg_match('/^\d{1,15}$/D',$v) ? (float)$v : null;
                }
                $fields['price_change_pct'] = 100*((float)$row['Close']/(float)$row['Previous']-1);
                $rows[$row['Stock Code']] = $fields;
            }
            $observed[$date] = ['date'=>$date,'sha256'=>$session['sha256'] ?? null,'universe'=>$summary['universe'],'rows'=>$rows];
        }
        ksort($observed);
        $dates = array_values($observed);
        $n = count($dates);
        $result = ['status'=>'INSUFFICIENT_HISTORY','available'=>$n,'required'=>self::BASELINE+1,
            'baseline_required'=>self::BASELINE,'threshold'=>self::THRESHOLD,
            'latest_date'=>$n ? $dates[$n-1]['date'] : null,'latest_eligible'=>0,
            'coverage'=>[],'activity'=>[],'anomalies'=>[],'calendar_verified'=>false,
            'units_verified'=>false,'admitted'=>false,'trading_authorized'=>false,
            'sources'=>array_map(fn($s)=>['date'=>$s['date'],'sha256'=>$s['sha256']],$dates)];
        if (!$n) {
            return $result;
        }
        $latest = $dates[$n-1]['rows'];
        $result['latest_eligible'] = count($latest);
        $prior = array_slice($dates,0,-1);
        foreach (['volume','value','frequency'] as $field) {
            $coverage = ['latest_valid'=>0,'baseline_complete'=>0,'scored'=>0,'zero_mad'=>0,'insufficient'=>0,'zero_median'=>0];
            $ratios = [];
            $flags = [];
            foreach ($latest as $ticker=>$row) {
                $current = $row[$field];
                if ($current === null) {
                    continue;
                }
                $coverage['latest_valid']++;
                $history = [];
                foreach ($prior as $session) {
                    $v = $session['rows'][$ticker][$field] ?? null;
                    if ($v !== null) {
                        $history[] = $v;
                    }
                }
                if (count($history) < self::BASELINE) {
                    $coverage['insufficient']++;
                    continue;
                }
                $coverage['baseline_complete']++;
                $median = $this->median($history);
                $mad = $this->median(array_map(fn($v)=>abs($v-$median),$history));
                $ratio = $median > 0 ? $current/$median : null;
                $score = $mad > 0 ? 0.6744897501960817*($current-$median)/$mad : null;
                $coverage['zero_mad'] += (int)($mad===0.0);
                $coverage['zero_median'] += (int)($median===0.0);
                $coverage['scored'] += (int)($score!==null);
                $item = ['ticker'=>(string)$ticker,'field'=>$field,'latest_raw'=>$current,
                    'baseline_median'=>$median,'baseline_mad'=>$mad,'ratio'=>$ratio,'robust_z'=>$score,
                    'price_change_pct'=>$row['price_change_pct'],'baseline_count'=>count($history),
                    'status'=>$score===null ? 'ZERO_MAD_ABSTAIN' : (abs($score)>=self::THRESHOLD ? 'CANDIDATE' : 'NOT_FLAGGED')];
                if ($ratio!==null) {
                    $ratios[] = $item;
                }
                if ($item['status']==='CANDIDATE') {
                    $flags[] = $item;
                }
            }
            usort($ratios,fn($a,$b)=>($b['ratio']<=>$a['ratio']) ?: strcmp($a['ticker'],$b['ticker']));
            usort($flags,fn($a,$b)=>(abs($b['robust_z'])<=>abs($a['robust_z'])) ?: strcmp($a['ticker'],$b['ticker']));
            $coverage['candidate_count'] = count($flags);
            $result['coverage'][$field] = $coverage;
            $result['activity'][$field] = array_slice($ratios,0,10);
            $result['anomalies'][$field] = array_slice($flags,0,10);
        }
        if ($n===self::BASELINE+1) {
            $result['status'] = array_sum(array_column($result['coverage'],'scored'))>0 ? 'OBSERVED_ONLY' : 'NO_SCORABLE_BASELINE';
        }
        return $result;
    }

    private function median(array $values): float
    {
        sort($values,SORT_NUMERIC);
        $n=count($values);
        $i=intdiv($n,2);
        return $n%2 ? (float)$values[$i] : ($values[$i-1]+$values[$i])/2;
    }
}
