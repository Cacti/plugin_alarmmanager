<?php
// SPDX-License-Identifier: GPL-2.0-or-later
final class QueryBuilderTest extends AlarmManagerTestCase {
	private function document(array $conditions): string {
		return json_encode(['version' => 1, 'conditions' => $conditions], JSON_THROW_ON_ERROR);
	}
	public function testGroupsAndWildcardValuesRemainBoundWithinVisibilityAndMode(): void {
		self::alarmmanager_test_load('includes/reports.php');
		$query=$this->document([
			['field'=>'source','operator'=>'=','value'=>"x' OR 1=1 --"],
			['join'=>'OR','negative'=>true,'rows'=>[
				['field'=>'summary','operator'=>'contains','value'=>'50%_!'],
				['join'=>'AND','field'=>'device_id','operator'=>'>=','value'=>'42']
			]]
		]);
		$filters=alarmmanager_report_filters(['query'=>$query]);
		[$where,$params]=alarmmanager_report_where(['device_sql'=>'e.device_id = 42'],$filters);
		$this->assertStringStartsWith('(e.device_id = 42) AND e.mode = ? AND e.received_at >= ? AND e.received_at < ? AND (',$where);
		$this->assertStringNotContainsString("x' OR",$where);
		$this->assertStringContainsString(' OR (NOT (',$where);
		$this->assertSame(['centralized',$filters['from'],$filters['to'],"x' OR 1=1 --",'%50!%!_!!%','42'],$params);

	}
	public function testFieldsUseTheReportAliasesAndCurrentSeverity(): void {
		self::alarmmanager_test_load('includes/reports.php');
		foreach (['deliveries'=>'d.status','attempts'=>'a.outcome','processing'=>'j.status'] as $kind=>$column) {
			$query=$this->document([['field'=>'status','operator'=>'=','value'=>'failed']]);
			$filters=alarmmanager_report_filters(['tab'=>$kind,'query'=>$query]);
			[$where,$params]=alarmmanager_report_where(['device_sql'=>'1=1'],$filters,null,'e.severity',$kind);
			$this->assertStringContainsString('('.$column.' = ?)',$where);$this->assertSame(['centralized','failed'],$params);
		}
		$query=$this->document([['field'=>'severity','operator'=>'=','value'=>'critical']]);
		$filters=alarmmanager_report_filters(['query'=>$query]);
		[$where]=alarmmanager_report_where(['device_sql'=>'1=1'],$filters,null,'s.severity');
		$this->assertStringContainsString('(s.severity = ?)',$where);
	}
	public function testRejectsMalformedUnknownOversizedAndDeepFilters(): void {
		self::alarmmanager_test_load('includes/reports.php');$fields=alarmmanager_report_query_fields();
		$leaf=['field'=>'source','operator'=>'=','value'=>'cacti'];$deep=$leaf;
		for($i=0;$i<18;$i++)$deep=['rows'=>[$deep]];
		$bad=['','{',str_repeat('x',8193),json_encode(['version'=>2,'conditions'=>[$leaf]]),$this->document([]),$this->document([['field'=>'mode','operator'=>'=','value'=>'observe']]),$this->document([['field'=>'source','operator'=>'like','value'=>'%']]),$this->document([['field'=>'device_id','operator'=>'=','value'=>'42 OR 1=1']]),$this->document([['field'=>'source','operator'=>'=','value'=>['x']]]),$this->document([['join'=>['AND']]+$leaf]),$this->document(array_fill(0,129,['join'=>'AND']+$leaf)),$this->document([$deep])];
		foreach($bad as $query){
			try{AlarmManager\QueryBuilder::compile($query,$fields);$this->fail('Invalid filter accepted');}catch(InvalidArgumentException $error){$this->assertNotSame('',$error->getMessage());}
		}
		$this->expectException(InvalidArgumentException::class);
		alarmmanager_report_filters(['tab'=>'events','query'=>$this->document([['field'=>'status','operator'=>'=','value'=>'failed']])]);
	}
	public function testPaginationKeepsQueryButEventDetailsDoNotUseAnotherReportsAliases(): void {
		self::alarmmanager_test_load('includes/web_view.php');
		$query=$this->document([['field'=>'status','operator'=>'=','value'=>'failed']]);
		$filters=alarmmanager_report_filters(['tab'=>'deliveries','query'=>$query]);
		parse_str(parse_url(alarmmanager_report_url($filters,'deliveries',['page'=>2]),PHP_URL_QUERY),$params);
		$this->assertSame($query,$params['query']);$this->assertSame('2',$params['page']);
		parse_str(parse_url(alarmmanager_report_url($filters,'events',['event_id'=>12]),PHP_URL_QUERY),$params);
		$this->assertArrayNotHasKey('query',$params);
	}
}
