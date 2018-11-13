<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Http\Requests;
use App\Http\Controllers\Controller;

use DB;

class EtlApiController extends Controller
{
    public function GetChiefEmpDeriveLevel($paramEmp, $paramDeriveLevel)
	{
		$chiefEmpId = 0;
		$initChiefEmp = DB::table('employee')
			->select('chief_emp_code')
			->where('emp_code', $paramEmp)
			->get();
		
		$curChiefEmp = $initChiefEmp[0]->chief_emp_code;

		while ($curChiefEmp != "0") {
			$getChief = DB::table('employee')
				->select('emp_id', 'level_id', 'chief_emp_code')
				->where('emp_code', $curChiefEmp)
				->get();
			
			if(! empty($getChief) ){
				if($getChief[0]->level_id == $paramDeriveLevel){ 
					$chiefEmpId = $getChief[0]->emp_id;
					$curChiefEmp = "0";
				} else {
					if($getChief[0]->chief_emp_code != "0"){
						$curChiefEmp = $getChief[0]->chief_emp_code;
					} else {
						$curChiefEmp = "0";
					}
				}
			} else {
				$curChiefEmp = "0";
			}
		}
		
		return response()->json(['emp_id'=>$chiefEmpId]);
    }
    

    public function GetParentOrgDeriveLevel($paramOrg, $paramDeriveLevel)
	{
		$parentOrgId = 0;
		$initParentOrg = DB::table('org')
			->select('parent_org_code')
			->where('org_code', $paramOrg)
			->get();

		$curParentOrg = $initParentOrg[0]->parent_org_code;

		while ($curParentOrg != "0") {
			$getChief = DB::table('org')
				->select('org_id', 'level_id', 'parent_org_code')
				->where('org_code', $curParentOrg)
				->get();
			
			if(! empty($getChief) ){
				if($getChief[0]->level_id == $paramDeriveLevel){ 
					$parentOrgId = $getChief[0]->org_id;
					$curParentOrg = "0";
				} else {
					if($getChief[0]->parent_org_code != "0" || $getChief[0]->parent_org_code != ""){
						$curParentOrg = $getChief[0]->parent_org_code;
					} else {
						$curParentOrg = "0";
					}
				}
			} else {
				$curParentOrg = "0";
			}
		}
		
        return response()->json(['org_id'=>$parentOrgId]);
	}
}
