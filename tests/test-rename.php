<?php
define('ABSPATH','/'); function sanitize_title($s){return trim(preg_replace('/[^a-z0-9]+/','-',strtolower($s)),'-');}
require __DIR__ . '/../givsen-supplier/includes/class-gsup-creator.php';
$m=new ReflectionMethod('GSUP_Creator','renamer'); $m->setAccessible(true);
function sku($vals){ $p=[]; foreach($vals as $n=>$v) $p[]=['name'=>$n,'value'=>$v,'is_ship'=>false]; return ['props'=>$p]; }
$fail=0; function ok($c,$m){global $fail; echo ($c?'PASS ':'FAIL ').$m."\n"; if(!$c)$fail++;}
// "1PC-Red" and "Red" both tidied to "Red".
list($n,$v)=$m->invoke(null,['Color'],[sku(['Color'=>'1PC-Red']),sku(['Color'=>'Red'])],['names'=>[],'values'=>['Color'=>['1PC-Red'=>'Red','Red'=>'Red']]]);
ok($v('Color','1PC-Red')!==$v('Color','Red'),'values stay distinct: '.$v('Color','1PC-Red').' / '.$v('Color','Red'));
// Rename Color→Size while Size exists.
list($n,$v)=$m->invoke(null,['Color','Size'],[sku(['Color'=>'Red','Size'=>'M'])],['names'=>['Color'=>'Size','Size'=>'Size'],'values'=>[]]);
ok(sanitize_title($n('Color'))!==sanitize_title($n('Size')),'names stay distinct: '.$n('Color').' / '.$n('Size'));
// Three values all renamed alike.
list($n,$v)=$m->invoke(null,['C'],[sku(['C'=>'A']),sku(['C'=>'B']),sku(['C'=>'A '])],['names'=>[],'values'=>['C'=>['A'=>'X','B'=>'X','A '=>'X']]]);
$out=[$v('C','A'),$v('C','B'),$v('C','A ')]; ok(count(array_unique(array_map('strtolower',$out)))===3,'three distinct: '.implode(' / ',$out));
echo $fail ? "$fail FAILED\n" : "ALL PASSED\n"; exit( $fail ? 1 : 0 );
