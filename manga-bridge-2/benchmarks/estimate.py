"""Model network durations explicitly; NEVER label assumptions as measurements."""
import csv, json, math
from pathlib import Path
root=Path('/tmp/mb2bench')
profiles=[('poucos',10,12),('medios',50,20),('muitos',200,24)]
rows=[]; actual=[]
for name,chapters,pages in profiles:
    wp=json.loads((root/(name+'.json')).read_text())
    assert wp['chapters_published']==10*chapters
    actual.append(wp)
    # Per manga: one HTML catalog + one cover header + one chapter HTML
    # and one Range image probe per page; assumes no duplicates or pagination.
    requests_per_manga=2+chapters*(1+pages)
    runs_per_manga=math.ceil(requests_per_manga/300)
    possible=runs_per_manga <=4
    for rtt in (0.2,0.6,1.2):
        network_seconds=(10*requests_per_manga)*max(rtt,0.15)
        overhead_seconds=(10*runs_per_manga)*30  # assumption, not measured
        eta=(network_seconds+overhead_seconds+wp['duration_seconds'])/60 if possible else None
        rows.append({
            'profile':name,'manga':10,
            'chapters_per_manga':chapters,'pages_per_chapter':pages,
            'total_chapters':10*chapters,'total_pages':10*chapters*pages,
            'wordpress_measured_seconds':wp['duration_seconds'],
            'wordpress_measured_sql_queries':wp['database_queries'],
            'origin_requests_per_manga_assumed':requests_per_manga,
            'origin_requests_for_10_assumed':requests_per_manga*10,
            'minimum_actions_runs_per_manga':runs_per_manga,
            'fits_current_4_attempt_limit':str(possible).lower(),
            'origin_request_latency_assumed_s':rtt,
            'github_startup_overhead_assumed_s_per_run':30,
            'modeled_end_to_end_minutes':round(eta,2) if possible else 'NOT_FEASIBLE',
            'scope':'synthetic WordPress publication + hypothetical source latency'
        })
    print(f'PROFILE {name}: measured WP {wp["duration_seconds"]:.3f}s; '
          f'chapters={10*chapters}; source calls modeled={10*requests_per_manga}; '
          f'actions runs/manga minimum={runs_per_manga}; fits current cap={possible}')
with (root/'estimated_scenarios.csv').open('w',newline='') as f:
    writer=csv.DictWriter(f,fieldnames=list(rows[0]))
    writer.writeheader();writer.writerows(rows)
(root/'benchmark_summary.json').write_text(json.dumps({
    'scope':'10 mangas per size in disposable WordPress + source network model, not live importer',
    'wordpress_measurements':actual,
    'model':rows,
    'limits':{'calls_per_actions_run':300,'max_runs_per_manga':4,
              'min_gap_s':0.15,'assumed_startup_per_run_s':30},
    'warnings':[
        'Synthetic image-verification receipts only in disposable CI database.',
        'No media download/real-origin request or running full plugin importer.',
        'No measurement on user production server, MariaDB, Cloudflare or WordPress staging.',
        'Network RTT and GitHub startup assumptions are NOT measured.',
        'Excludes retries, timeouts, rate limiting, failures, additional source pagination, and scheduling wait.',
        'Large profile cannot complete under current four-run limit.'
    ]
},indent=2))
