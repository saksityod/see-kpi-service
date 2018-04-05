To {{$chief_emp_name}}
<br/>
<br/>
	<p>ข้อมูลของคุณ {{$emp_name}} อยู่ที่สถานะ "{{$status}}" กรุณา Click Link
	@if ($assignment_flag == 1)
	<a href="{{$web_domain}}assignment-popup/?emp_result_id={{$emp_result_id}}&appraisal_type_id={{$appraisal_type_id}}">{{$web_domain}}assignment-popup/?emp_result_id={{$emp_result_id}}&appraisal_type_id={{$appraisal_type_id}}</a>
	@else
	<a href="{{$web_domain}}kpi-result-popup/?emp_result_id={{$emp_result_id}}&appraisal_type_id={{$appraisal_type_id}}">{{$web_domain}}kpi-result-popup/?emp_result_id={{$emp_result_id}}&appraisal_type_id={{$appraisal_type_id}}</a>	
	@endif
	ระบบเพื่อทำการประเมิน</p>
<br/>
<br/>
From SEE-KPI System	
