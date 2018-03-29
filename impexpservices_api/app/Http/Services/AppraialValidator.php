<?php
/**
 * Created by PhpStorm.
 * User: imake
 * Date: 12/21/17
 * Time: 11:55 PM
 */

namespace App\Http\Services;
use App\Model\AppraisalStructureModel;
use Excel;
use Log;
class AppraialValidator
{
    public  function validateTemplate($numberOfSheet,$request,$fixed_values,$fixed_key,$master_key,$master_service,$header_values,$bank_values,$number_values,$all_number_values){
        $structure_id = $request->structure_id;
        $appraisalStructure = AppraisalStructureModel::find($structure_id);
        $structure_name = $appraisalStructure->structure_name;
        //Log::info($structure_name);

        $this->result_status = 1;
        $this->code = "S001";
        $this->msg = "import Success";
        $uom_items = AppraisalService::getUomMaster();
        $perspective_items = AppraisalService::getPerspectiveMaster();
        $value_type_items = AppraisalService::getValueTypeMaster();

        $appraisal_level_items = AppraisalService::getAppraisalLevelMaster();
        $org_tems = AppraisalService::getOrgMaster();
        $position_items = AppraisalService::getPositionMaster();

        $uom_items_key = array();
        foreach ($uom_items as $i) {
            array_push($uom_items_key, $i->uom_id);
        }
        $perspective_items_key = array();
        foreach ($perspective_items as $i) {
            array_push($perspective_items_key, $i->perspective_id);
        }
        $value_type_items_key = array();
        foreach ($value_type_items as $i) {
            array_push($value_type_items_key, $i->value_type_id);
        }
        $appraisal_level_items_key = array();
        foreach ($appraisal_level_items as $i) {
            array_push($appraisal_level_items_key, $i->level_id);
        }
        $org_tems_key = array();
        foreach ($org_tems as $i) {
            array_push($org_tems_key, $i->org_id);
        }
        $position_items_key = array();
        foreach ($position_items as $i) {
            array_push($position_items_key, $i->position_id);
        }

        $services = [$uom_items_key,$perspective_items_key,$value_type_items_key,$appraisal_level_items_key,$org_tems_key,$position_items_key];


        $fixed_key_1 =  ['0','1'];
        $fixed_key_2 =  ['1','2'];
        $fixed_key_3 =  ['1','2','3'];
        $fixed_keys = [$fixed_key_1,$fixed_key_2,$fixed_key_3];



        //$f='/Users/imake/Desktop/master_import_okr.xlsx';
        //$f='/Users/imake/Desktop/master_import_learning.xlsx';
        //$f='/Users/imake/Desktop/master_import_attendance.xlsx';
        //$f='/Users/imake/Desktop/master_import_okr-21.xlsx';
        foreach ($request->file() as $f) {
            for ($k = 0;$k<$numberOfSheet ; $k++) {
                //Log::info('into looop '.$k);
                Excel::selectSheetsByIndex($k)->load($f, function($reader) use ($fixed_keys, $fixed_key, $fixed_values, $numberOfSheet, $master_service, $services, $master_key, $header_values, $all_number_values, $k, $bank_values, $number_values,$structure_name) {
                    $sheet =  $reader->getExcel()->getSheet($k);
                    $sheet_name = $sheet->getTitle();
                    $pos = strpos($sheet_name,$structure_name);
                    //Log::info('pos['.$pos.']');
                    //Log::info(gettype($pos));
                    if(!strlen((string)$pos)>0){
                        $this->result_status = 0;
                        $this->code = 'E004';
                        $this->msg = "เลือก Template ไม่ถูกต้อง";
                        goto end;
                    }
                    foreach ($header_values as $header) {
                        $head_val = $sheet->getCell($header.'1')->getValue() ;
                        if ( !(!empty($head_val) && strlen(trim($head_val))>0) ) {
                            $this->result_status = 0;
                            $this->code = 'E005';
                            $this->msg = "Template ไม่ถูกต้อง Sheet[".$sheet_name.']!'.$header.'1';
                            goto end;
                        }
                    }
                    $head_val = $sheet->getCell("F2")->getValue() ;
                    Log::info('check value['.$head_val.']');
                    if ( !( strlen(trim($head_val))>0 ) ) {
                        Log::info('check true['.$head_val.']');
                        Log::info('check true['.!empty($head_val).']');
                        Log::info('check true['.strlen(trim($head_val)).']');
                    }
                    foreach ($header_values as $header) {
                        $head_val = $sheet->getCell($header.'2')->getValue() ;
                        if ( !( strlen(trim($head_val))>0 )  ) {
                            $this->result_status = 0;
                            $this->code = 'E006';
                            $this->msg = "กรุณากรอกข้อมูลช่อง Sheet[".$sheet_name.']!'.$header.'2';
                            goto end;
                        }
                    }
                    //$head_val = $sheet->getCell("A1")->getValue() ;
                    //Log::info('head_val['.$head_val.']');
                    for ($i = 2; ; $i++) {
                        $cds_name = $sheet->getCell('A'.$i)->getValue() ;
                        if ( !empty($cds_name) && strlen(trim($cds_name))>0 ) {
                            //Log::info($cds_name);
                            foreach ($bank_values as $bank) {
                                $bank_val = $sheet->getCell($bank.$i)->getValue() ;
                                //if ( !empty($bank_val) && strlen(trim($bank_val))>0 ) {
                                if ( !(!empty($bank_val) && strlen(trim($bank_val))>0) ) {
                                    $this->result_status = 0;
                                    $this->code = 'E002';
                                    $this->msg = "กรุณากรอกข้อมูลช่อง Sheet[".$sheet_name.']!'.$bank.$i;
                                    goto end;
                                }
                            }
                            foreach ($number_values as $number) {
                                $num_val = $sheet->getCell($number.$i)->getValue() ;
                                //Log::info($num_val);
                                if(!is_numeric($num_val) ){
                                    $this->result_status = 0;
                                    $this->code = 'E003';
                                    $this->msg = "กรุณากรอกข้อมูล ตัวเลข ช่อง Sheet[".$sheet_name.']!'.$number.$i;
                                    goto end;
                                }
                            }
                            foreach ($all_number_values as $all_number) {
                                $all_number_val = $sheet->getCell($all_number.$i)->getValue() ;
                                //Log::info($num_val);
                                if(strtolower(trim($all_number_val)) != 'all' && !is_numeric($all_number_val) ){
                                    $this->result_status = 0;
                                    $this->code = 'E003';
                                    $this->msg = "กรุณากรอกข้อมูล ตัวเลข ช่อง Sheet[".$sheet_name.']!'.$all_number.$i;
                                    goto end;
                                }
                            }

                            // check key master
                            if($numberOfSheet==1) {
                                for ($j = 0; $j < sizeof($master_key); $j++) {
                                    $key_of_service = $master_service[$j];
                                    $master_val = $sheet->getCell($master_key[$j] . $i)->getValue();
                                    //Log::info('$master_val[' . $master_val . ']');
                                    if (strtolower(trim($master_val)) != 'all') {
                                        if (!in_array(intval($master_val), $services[$key_of_service])) {
                                            $this->result_status = 0;
                                            $this->code = 'E007';
                                            $this->msg = "ไม่มีข้อมูลใน VLOOKUP Sheet[" . $sheet_name . ']!' . $master_key[$j] . $i;
                                            goto end;
                                        }
                                    }
                                }
                                //$fixed_values,$fixed_key
                                // for fixed value
                                //Log::info("fixed_values".sizeof($fixed_values));
                                for ($j = 0; $j < sizeof($fixed_values); $j++) {
                                    $key_of_service = $fixed_key[$j];
                                    $master_val = $sheet->getCell($fixed_values[$j] . $i)->getValue();
                                    //Log::info('$master_val[' . $master_val . ']');
                                        if (!in_array(intval($master_val), $fixed_keys[$key_of_service])) {
                                            $this->result_status = 0;
                                            $this->code = 'E008';
                                            $this->msg = "ใส่่ข้อมูลไม่ถูกต้อง Sheet[" . $sheet_name . ']!' . $fixed_values[$j] . $i;
                                            goto end;
                                        }
                                }

                            }else{
                                for ($j = 0; $j < sizeof($master_key); $j++) {
                                    $key_of_service = $master_service[$j]+$numberOfSheet+$k;
                                    $master_val = $sheet->getCell($master_key[$j] . $i)->getValue();
                                    //Log::info("key_of_service[".$key_of_service."]");
                                    //Log::info('$master_val for detail[' . $master_val . '], service size['.sizeof($services[$key_of_service]).']');
                                    if (strtolower(trim($master_val)) != 'all') {
                                        if (!in_array(intval($master_val), $services[$key_of_service])) {
                                            $this->result_status = 0;
                                            $this->code = 'E007';
                                            $this->msg = "ไม่มีข้อมูลใน VLOOKUP Sheet[" . $sheet_name . ']!' . $master_key[$j] . $i;
                                            goto end;
                                        }
                                    }

                                    //Log::info(sizeof($services[$key_of_service]));
                                }
                            }


                        }else{
                            break;
                        }
                    }
                    end:
                });
                if($this->result_status == 0)
                    break;
            }

        }

        $result_obj = new \stdClass; // Instantiate stdClass object
        $result_obj->result_status = $this->result_status;
        $result_obj->code = $this->code;
        $result_obj->msg = $this->msg;
        return $result_obj;
    }
}