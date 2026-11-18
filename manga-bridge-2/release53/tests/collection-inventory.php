<?php
require __DIR__.'/preflight.php';
$transients=[];$before=count(MBI_Global_Diagnostic::$calls);$bad=str_replace('https://mangalivre.blog/wp-content/uploads/cover-real.jpg','https://mangalivre.blog/no-cover.jpg',roothtml());$pref->setValue($i,array_merge([$url=>$bad],$responses));
$complete=false;
for($round=0;$round<6;$round++){
 MBI_Resource_Guard::$left=12;
 if($round===3)$pref->setValue($i,array_merge($pref->getValue($i),[$url=>$bad]));
 try{$result=$method->invoke($i,$url,false,true);$complete=true;break;}catch(MBI_Resource_Wait $e){}
}
ok($complete&&count($result['chapter_urls'])===254,'collection gathers full inventory despite rejected metadata');
ok(count(MBI_Global_Diagnostic::$calls)===$before,'collection does not probe covers or Worker while gathering pages');
ok(count($pref->getValue($i))===0,'collection checkpoint resumes pagination rather than rereading root every round');
echo "PASS $checks collection plus previous inventory checks\n";
