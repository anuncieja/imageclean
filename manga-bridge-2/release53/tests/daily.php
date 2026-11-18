<?php
require __DIR__.'/common.php';
class MBI_Media_Repair{}class MBI_DB{}
$cleared=[];function wp_clear_scheduled_hook($name){global $cleared;$cleared[]=$name;}
require PLUGIN_ROOT . '/includes/class-mbi-media-monitor.php';
$m=new MBI_Media_Monitor(new MBI_Media_Repair,new MBI_DB);
$options['mbi_media_daily_enabled']=1;$options['mbi_media_daily_deferred']=1;$options['mbi_media_daily']=['id'=>'RUN','active'=>true,'mode'=>'daily','checked'=>17];
$m->schedule();ok($options['mbi_media_daily_enabled']===0&&!$options['mbi_media_daily']['active'],'upgrade stops the previously implicit automatic sweep');
ok($options['mbi_media_daily']['checked']===17&&!isset($options['mbi_media_daily_deferred']),'daily results retained and automatic retry removed');
ok(in_array('mbi_media_daily_scan',$cleared)&&in_array('mbi_media_daily_continue',$cleared),'automatic scheduled scan and continuation cancelled');
$options['mbi_media_daily_enabled']=1;$m->schedule();ok($options['mbi_media_daily_enabled']===1,'explicit activation after upgrade not overwritten');
unset($options['mbi_daily_policy51']);$options['mbi_media_daily']=['active'=>true,'mode'=>'chapters','checked'=>8];$m->schedule();ok($options['mbi_media_daily']['active']&&$options['mbi_media_daily']['checked']===8,'manual repair left intact');
echo "PASS $checks automatic sweep policy checks\n";
