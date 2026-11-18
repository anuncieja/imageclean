<?php
use MangaBridge2\Job;
use MangaBridge2\Plugin;
use MangaBridge2\Store;
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'staging' || !defined('MBI_VERSION')) {
    throw new RuntimeException('Disposable co-install CI only');
}
function check_dispatch(bool $ok, string $reason): void {
    if (!$ok) { throw new RuntimeException($reason); }
    echo "PASS $reason\n";
}
define('MBI2_WORKFLOW_REGISTERED', true);
define('MBI2_GITHUB_TOKEN', 'fixture-not-a-github-credential');
$endpoint='https://api.github.com/repos/anuncieja/imageclean/actions/workflows/manga-bridge-executor.yml/dispatches';
$id=wp_generate_uuid4();
$generation=wp_generate_uuid4();
check_dispatch(Store::create(Job::create($id,$generation,'https://mangalivre.blog/manga/coinstall/',Plugin::hosts(),time())),'co-install job created');
$calls=0;
$mock=static function ($pre,$args,$url) use ($endpoint,$id,&$calls) {
    check_dispatch($url===$endpoint && $args['method']==='POST','dispatch targets fixed GitHub workflow');
    $body=json_decode($args['body'],true,32,JSON_THROW_ON_ERROR);
    check_dispatch($body['inputs']['job_id']===$id && $body['return_run_details']===true,'dispatch sends only fixture ID and requests run details');
    $calls++;
    return ['response'=>['code'=>200],'body'=>'{"workflow_run_id":4242}'];
};
add_filter('pre_http_request',$mock,100,3);
Plugin::dispatch($id);
Plugin::dispatch($id);
remove_filter('pre_http_request',$mock,100);
$job=Store::read($id);
check_dispatch($calls===1 && $job['state']==='queued' && $job['reason']==='github_dispatched' && $job['github_run_id']===4242,'HTTP 200 accepted and duplicate dispatch suppressed');
check_dispatch(wp_next_scheduled('mbi2_watchdog',[$id,$generation,''])!==false,'dispatch scheduled the bounded watchdog');
file_put_contents('/tmp/mbi2-http-job-id',$id);
