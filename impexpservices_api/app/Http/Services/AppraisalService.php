<?php
/**
 * Created by PhpStorm.
 * User: imake
 * Date: 12/19/17
 * Time: 10:47 PM
 */

namespace App\Http\Services;
use App\Model\AppraisalItemModel;
use App\Model\AppraisalStructureModel;
use App\Model\CDSModel;
use Excel;
use Illuminate\Support\Facades\DB;
use Log;

class AppraisalService
{
    /*
    ชีทหน่วยวัด (SELECT uom_name, uom_id FROM uom where is_active = 1 order by uom_id)
    ชีทมุมมองBSC (SELECT perspective_name, perspective_id FROM perspective where is_active = 1 order by perspective_id)
    ชีทเกณฑ์ประเมิน (SELECT value_type_name, value_type_id FROM value_type order by value_type_id)
     */
    // get uom Master
    public static function getUomMaster(){
        //SELECT uom_name, uom_id FROM uom where is_active = 1 order by uom_id
        return AppraisalService::getMaster("uom_name","uom_id","uom",1,"uom_id");
    }
    // get perspective Master
    public static function getPerspectiveMaster(){
        //SELECT perspective_name, perspective_id FROM perspective where is_active = 1 order by perspective_id
        return AppraisalService::getMaster("perspective_name","perspective_id","perspective",1,"perspective_id");
    }
    // get value_type Master
    public static function getValueTypeMaster(){
        //SELECT value_type_name, value_type_id FROM value_type order by value_type_id
        return AppraisalService::getMaster("value_type_name","value_type_id","value_type",null,"value_type_id");
    }

    /*
     ชีทระดับการประเมิน (select appraisal_level_name, level_id from appraisal_level where is_active = 1 order by level_id)
     ชีทหน่วยงาน (select org_name, org_id from org where is_active = 1 order by org_id)
     ชีทหน่วยงาน (select position_name, position_id from position where is_active = 1 order by position_id)
     */
    // get appraisal_level Master
    public static function getAppraisalLevelMaster(){
        //select appraisal_level_name, level_id from appraisal_level where is_active = 1 order by level_id
        return AppraisalService::getMaster("appraisal_level_name","level_id","appraisal_level",1,"level_id");
    }
    // get org Master
    public static function getOrgMaster(){
        //select appraisal_level_name, level_id from appraisal_level where is_active = 1 order by level_id
        return AppraisalService::getMaster("org_name","org_id","org",1,"org_id");
    }
    // get position Master
    public static function getPositionMaster(){
        //select appraisal_level_name, level_id from appraisal_level where is_active = 1 order by level_id
        return AppraisalService::getMaster("position_name","position_id","position",1,"position_id");
    }

    // create  Master Sheet
    public static function createMasterSheet($excel){
        $excel->sheet('หน่วยวัด', function($sheet) {
            $sheet->appendRow(array('uom_name', 'uom_id'));
            $items = AppraisalService::getUomMaster();
            foreach ($items as $i) {
                $sheet->appendRow(array(
                    $i->uom_name,
                    $i->uom_id
                ));
                $sheet->getStyle('A1:B1')->getAlignment()->applyFromArray(
                    array('horizontal' => 'center','vertical' => 'center')
                );
            }
        });
        $excel->sheet('มุมมองBSC', function($sheet) {
            $sheet->appendRow(array('perspective_name', 'perspective_id'));
            $items = AppraisalService::getPerspectiveMaster();
            foreach ($items as $i) {
                $sheet->appendRow(array(
                    $i->perspective_name,
                    $i->perspective_id
                ));
                $sheet->getStyle('A1:B1')->getAlignment()->applyFromArray(
                    array('horizontal' => 'center','vertical' => 'center')
                );
            }
        });
        $excel->sheet('เกณฑ์ประเมิน', function($sheet) {
            $sheet->appendRow(array('value_type_name', 'value_type_id'));
            $items = AppraisalService::getValueTypeMaster();
            foreach ($items as $i) {
                $sheet->appendRow(array(
                    $i->value_type_name,
                    $i->value_type_id
                ));
                $sheet->getStyle('A1:B1')->getAlignment()->applyFromArray(
                    array('horizontal' => 'center','vertical' => 'center')
                );
            }
        });
        //return $excel;
    }
    // create  Detail Master Sheet
    public static function createDetailMasterSheet($excel){
        $excel->sheet('ระดับการประเมิน', function($sheet) {
            $sheet->appendRow(array('appraisal_level_name', 'level_id'));
            $items = AppraisalService::getAppraisalLevelMaster();
            foreach ($items as $i) {
                $sheet->appendRow(array(
                    $i->appraisal_level_name,
                    $i->level_id
                ));
                $sheet->getStyle('A1:B1')->getAlignment()->applyFromArray(
                    array('horizontal' => 'center','vertical' => 'center')
                );
            }
        });
        $excel->sheet('หน่วยงาน', function($sheet) {
            $sheet->appendRow(array('org_name', 'org_id'));
            $items = AppraisalService::getOrgMaster();
            foreach ($items as $i) {
                $sheet->appendRow(array(
                    $i->org_name,
                    $i->org_id
                ));
                $sheet->getStyle('A1:B1')->getAlignment()->applyFromArray(
                    array('horizontal' => 'center','vertical' => 'center')
                );
            }
        });
        $excel->sheet('ตำแหน่ง', function($sheet) {
            $sheet->appendRow(array('position_name', 'position_id'));
            $items = AppraisalService::getPositionMaster();
            foreach ($items as $i) {
                $sheet->appendRow(array(
                    $i->position_name,
                    $i->position_id
                ));
                $sheet->getStyle('A1:B1')->getAlignment()->applyFromArray(
                    array('horizontal' => 'center','vertical' => 'center')
                );
            }
        });
        //return $excel;
    }
    // create  Detail Sheet
    public static function createDetailSheet($structure_id,$name,$excel){
        $appraisalItems = AppraisalItemModel::where('structure_id', $structure_id)
                ->orderBy('item_id', 'asc')
                ->get();


        //Attendance
        $excel->sheet($name.'-Level', function($sheet) use ($appraisalItems) {
            $sheet->appendRow(array('รหัสตัวชี้วัด', 'ตัวชี้วัด', 'ระดับการประเมิน', 'รหัสระดับการประเมิน (VLOOKUP)'));
            $sheet->setWidth('A', 10);
            $sheet->setWidth('B', 38);
            $sheet->setWidth('C', 18);
            $sheet->setWidth('D', 27);
            $sheet->getStyle('A1:D1')->getAlignment()->applyFromArray(
                array('horizontal' => 'center','vertical' => 'center')
            );
            foreach ($appraisalItems as $item) {
                $sheet->appendRow(array($item->item_id, $item->item_name, '', ''));
            }
        });
        $excel->sheet($name.'-Org', function($sheet) use ($appraisalItems) {
            $sheet->appendRow(array('รหัสตัวชี้วัด', 'ตัวชี้วัด', 'หน่วยงาน', 'รหัสหน่วยงาน (VLOOKUP)'));
            $sheet->setWidth('A', 10);
            $sheet->setWidth('B', 38);
            $sheet->setWidth('C', 18);
            $sheet->setWidth('D', 27);
            $sheet->getStyle('A1:D1')->getAlignment()->applyFromArray(
                array('horizontal' => 'center','vertical' => 'center')
            );
            foreach ($appraisalItems as $item) {
                $sheet->appendRow(array($item->item_id, $item->item_name, '', ''));
            }
        });
        $excel->sheet($name.'-Position', function($sheet) use ($appraisalItems) {
            $sheet->appendRow(array('รหัสตัวชี้วัด', 'ตัวชี้วัด', 'ตำแหน่ง', 'รหัสตำแหน่ง (VLOOKUP)'));
            $sheet->setWidth('A', 10);
            $sheet->setWidth('B', 38);
            $sheet->setWidth('C', 18);
            $sheet->setWidth('D', 27);
            $sheet->getStyle('A1:D1')->getAlignment()->applyFromArray(
                array('horizontal' => 'center','vertical' => 'center')
            );
            foreach ($appraisalItems as $item) {
                $sheet->appendRow(array($item->item_id, $item->item_name, '', ''));
            }
        });
        //return $excel;
    }
    public static function getMaster($label_1,$label_2,$table,$isActive=null,$order_column,$order_by='asc'){
        $masters = DB::table($table)
            ->select($label_1, $label_2);
            if (!empty($isActive)) {
                $masters = $masters->where('is_active', $isActive);
            }

        $masters = $masters->orderBy($order_column, $order_by)
            ->get();
        return $masters;
    }
    public  static function importMaster($request){
        $structure_id = $request->structure_id;
        Log::info("structure_id=".$structure_id);
        $appraisalStructure = AppraisalStructureModel::find($structure_id);
        $created_by = $request->user_id;
        Log::info("created_by=".$created_by);
        $form_id = $appraisalStructure->form_id;
        Log::info("form_id=".$appraisalStructure->form_id);
        //$f='/Users/imake/Desktop/master_import_okr.xlsx';
        foreach ($request->file() as $f) {
            Excel::selectSheetsByIndex(0)->load($f, function($reader) use ($structure_id, $appraisalStructure, $created_by ,$form_id) {
                $now = date("Y-m-d H:i:s");//now();
                $sheet =  $reader->getExcel()->getSheet(0);

                DB::transaction(function () use ($structure_id, $sheet, $now,$form_id, $created_by) {
                    for ($i = 2; ; $i++) {
                        $cds_name = $sheet->getCell('A'.$i)->getValue() ;

                        $is_corporate_kpi = $sheet->getCell('G'.$i)->getValue() ;
                        $uom_id = $sheet->getCell('I'.$i)->getValue() ;
                        $perspective_id = $sheet->getCell('J'.$i)->getValue() ;
                        $value_type_id = $sheet->getCell('K'.$i)->getValue() ;

                        $remind_condition_id = $sheet->getCell('E'.$i)->getValue() ;
                        $formula_cds_id = $sheet->getCell('H'.$i)->getValue() ;
                        $is_show_variance = $sheet->getCell('F'.$i)->getValue() ;
                        if ( !empty($cds_name) && strlen(trim($cds_name))>0 ) {
                            Log::info($cds_name);
                            if($form_id==1) {
                                $cds_id = null;
                                $cdsOld= CDSModel::where('cds_name', $cds_name)->get();
                                Log::info(sizeof($cdsOld));
                                if ( sizeof($cdsOld)>0){
                                    CDSModel::where('cds_id', $cdsOld[0]->cds_id)
                                        ->update(['cds_name' => $cds_name,'cds_desc' => $cds_name
                                                 , 'connection_id' => null , 'is_sql' => 0 , 'cds_sql' => null
                                                 ,'is_active' => 1 , 'created_by' => $created_by , 'created_dttm' => $now
                                                 ,'updated_by' => $created_by , 'updated_dttm' => $now]);
                                    $cds_id = $cdsOld[0]->cds_id;
                                }else{
                                    $cds = new CDSModel;
                                    $cds->cds_name = str_replace('"',"'",$cds_name);
                                    $cds->cds_desc = $cds_name;
                                    $cds->connection_id = null;
                                    $cds->is_sql = 0;
                                    $cds->cds_sql = null;
                                    $cds->is_active = 1;
                                    $cds->created_by = $created_by;
                                    $cds->created_dttm = $now;
                                    $cds->updated_by = $created_by;
                                    $cds->updated_dttm = $now;
                                    $cds->save();
                                    $cds_id = $cds->cds_id;
                                }

                            }
                            $appraisalItem = new AppraisalItemModel;
                            if ($form_id == 1) {
                                $item_name = str_replace('"',"'",$cds_name);
                                $appraisalItem->kpi_id = null;
                                $appraisalItem->item_name = str_replace('"',"'",$cds_name); // column ตัวชี้วัด ในไฟล์ master
                                $appraisalItem->structure_id = $structure_id; // จากพารามิเตอร์ structure ที่หน้าจอ
                                $appraisalItem->kpi_type_id = null;
                                $appraisalItem->is_corporate_kpi = $is_corporate_kpi;
                                $appraisalItem->perspective_id = $perspective_id;//column I ในไฟล์ master
                                $appraisalItem->uom_id = $uom_id;//	column H ในไฟล์ master
                                $appraisalItem->value_type_id = $value_type_id;//	column J ในไฟล์ master
                                $appraisalItem->remind_condition_id = $remind_condition_id;//column E ในไฟล์ master
                                $appraisalItem->baseline_value = 0;
                                $appraisalItem->formula_desc = '';
                                $formula_cds = 'sum';
                                if($formula_cds_id==2)
                                    $formula_cds = 'last';
                                else if($formula_cds_id==3)
                                    $formula_cds = 'avg';
                                $appraisalItem->formula_cds_id = '['.$formula_cds.':cds' . $cds_id . ']';//ถ้า column G ในไฟล์ master เป็น 1 บันทึกเป็น [sum:cds1]  -> 1 คือ cds_id จากชีท cds
                                //ถ้า column G ในไฟล์ master เป็น 2 บันทึกเป็น [last:cds1]  -> 1 คือ cds_id จากชีท cds
                                $appraisalItem->formula_cds_name = '&nbsp;<span class="not-allowed" contenteditable="false"><div class="font-blue">[</div>'.$formula_cds.':<span class="cds_name_inline">'.$item_name.'</span><span class="cds_id_inline ">cds'.$cds_id.'</span><div class="font-blue">]</div></span>';//	&nbsp;<span class="not-allowed" contenteditable="false"><div class="font-blue">[</div>last:<span class="cds_name_inline">ค่าใช้จ่ายดำเนินงานต่อรายได้สุทธิจากการดำเนินงาน</span><span class="cds_id_inline ">cds1</span><div class="font-blue">]</div></span>
                                // เปลี่ยนค่า cds_name_inline ที่ไฮไลท์สีแดงเป็น column ตัวชี้วัด ในไฟล์ master
                                // เปลี่ยนค่า cds_id_inline ที่ไฮไลท์สีแดงเป็น cds_id จากชีท cds
                                $appraisalItem->function_type = $formula_cds_id;
                                $appraisalItem->max_value = null;
                                $appraisalItem->unit_deduct_score = null;
                                $appraisalItem->value_get_zero = null;
                                $appraisalItem->is_show_variance = $is_show_variance;//column F ในไฟล์ master
                                $appraisalItem->is_active = 1;
                                $appraisalItem->created_by = $created_by;//user liferay ที่ loging
                                $appraisalItem->created_dttm = $now;//system datetime
                                $appraisalItem->updated_by = $created_by;//user liferay ที่ loging
                                $appraisalItem->updated_dttm = $now;// system datetime

                            } else if ($form_id == 2) {
                                $appraisalItem->kpi_id = null;
                                $appraisalItem->item_name = str_replace('"',"'",$cds_name); // column ตัวชี้วัด ในไฟล์ master
                                $appraisalItem->structure_id = $structure_id; // จากพารามิเตอร์ structure ที่หน้าจอ
                                $appraisalItem->kpi_type_id = null;
                                $appraisalItem->is_corporate_kpi = null;
                                $appraisalItem->perspective_id = null;
                                $appraisalItem->uom_id = null;
                                $appraisalItem->value_type_id = null;
                                $appraisalItem->remind_condition_id = null;
                                $appraisalItem->baseline_value = 0;
                                $appraisalItem->formula_desc = '';
                                $appraisalItem->formula_cds_id = '';
                                $appraisalItem->formula_cds_name = '';
                                $appraisalItem->function_type = null;
                                $appraisalItem->max_value = null;
                                $appraisalItem->unit_deduct_score = null;
                                $appraisalItem->value_get_zero = null;
                                $appraisalItem->is_show_variance = 0;
                                $appraisalItem->is_active = 1;
                                $appraisalItem->created_by = $created_by;//user liferay ที่ loging
                                $appraisalItem->created_dttm = $now;//system datetime
                                $appraisalItem->updated_by = $created_by;//user liferay ที่ loging
                                $appraisalItem->updated_dttm = $now;// system datetime

                            } else if ($form_id == 3) {
                                $max_value = $sheet->getCell('B'.$i)->getValue() ;
                                $unit_deduct_score = $sheet->getCell('C'.$i)->getValue() ;
                                $value_get_zero = $sheet->getCell('D'.$i)->getValue() ;
                                $appraisalItem->kpi_id = null;
                                $appraisalItem->item_name = str_replace('"',"'",$cds_name); // column ตัวชี้วัด ในไฟล์ master
                                $appraisalItem->structure_id = $structure_id; // จากพารามิเตอร์ structure ที่หน้าจอ
                                $appraisalItem->kpi_type_id = null;
                                $appraisalItem->is_corporate_kpi = null;
                                $appraisalItem->perspective_id = null;
                                $appraisalItem->uom_id = null;
                                $appraisalItem->value_type_id = null;
                                $appraisalItem->remind_condition_id = null;
                                $appraisalItem->baseline_value = 0;
                                $appraisalItem->formula_desc = '';
                                $appraisalItem->formula_cds_id = '';
                                $appraisalItem->formula_cds_name = '';
                                $appraisalItem->function_type = null;
                                $appraisalItem->max_value = $max_value;
                                $appraisalItem->unit_deduct_score = $unit_deduct_score;
                                $appraisalItem->value_get_zero = $value_get_zero;
                                $appraisalItem->is_show_variance = 0;
                                $appraisalItem->is_active = 1;
                                $appraisalItem->created_by = $created_by;//user liferay ที่ loging
                                $appraisalItem->created_dttm = $now;//system datetime
                                $appraisalItem->updated_by = $created_by;//user liferay ที่ loging
                                $appraisalItem->updated_dttm = $now;// system datetime

                            }
                            $appraisalItemOld= AppraisalItemModel::where('item_name', $cds_name)->get();
                            $item_id = null;
                            if ( sizeof($appraisalItemOld)>0){
                                AppraisalItemModel::where('item_id', $appraisalItemOld[0]->item_id)
                                    ->update(['kpi_id' => $appraisalItem->kpi_id,'item_name' => str_replace('"',"'",$appraisalItem->item_name), 'structure_id' => $appraisalItem->structure_id
                                        , 'kpi_type_id' => $appraisalItem->kpi_type_id , 'is_corporate_kpi' => $appraisalItem->is_corporate_kpi , 'perspective_id' => $appraisalItem->perspective_id
                                        ,'uom_id' => $appraisalItem->uom_id , 'value_type_id' => $appraisalItem->value_type_id , 'remind_condition_id' => $appraisalItem->remind_condition_id
                                        ,'baseline_value' => $appraisalItem->baseline_value , 'formula_desc' => $appraisalItem->formula_desc , 'formula_cds_id' => $appraisalItem->formula_cds_id
                                        ,'formula_cds_name' => $appraisalItem->formula_cds_name , 'max_value' => $appraisalItem->max_value , 'unit_deduct_score' => $appraisalItem->unit_deduct_score
                                        ,'value_get_zero' => $appraisalItem->value_get_zero , 'is_show_variance' => $appraisalItem->is_show_variance , 'is_active' => $appraisalItem->is_active
                                        ,'function_type' => $appraisalItem->function_type
                                        ,'created_by' => $created_by , 'created_dttm' => $now
                                        ,'updated_by' => $created_by , 'updated_dttm' => $now]);
                                $item_id = $appraisalItemOld[0]->item_id;
                            }else{
                                $appraisalItem->save();
                                $item_id = $appraisalItem->item_id;
                            }

                            if($form_id==1) {
                               // Log::info('$cds_id-->'.$cds_id);

                                $count = DB::table('kpi_cds_mapping')
                                    ->where('item_id','=',$item_id)
                                    ->where('cds_id','=',$cds_id)
                                    ->count();
                                //Log::info('$count-->'.$count);
                                if($count>0){
                                    DB::table('kpi_cds_mapping')
                                        ->where('item_id','=',$item_id)
                                        ->where('cds_id','=',$cds_id)
                                        /*
                                        ->where([
                                            ['item_id','=',$item_id ],
                                            ['cds_id','=',$cds_id ]
                                        ])
                                        */
                                        ->update(['created_by' => $created_by,'created_dttm' => $now]);
                                }else{
                                    DB::insert('insert into kpi_cds_mapping (item_id, cds_id,created_by,created_dttm) values (?, ?, ?, ?)', [$item_id, $cds_id, $created_by, $now]);
                                }
                            }
                        }else{
                            break;
                        }
                    }
                });


            });
        }

        //return $masters;
    }
    public static function importDetail($request){
        $created_by = $request->user_id;
        //$f='/Users/imake/Desktop/detail_import_okr.xlsx';
        foreach ($request->file() as $f) {
            DB::transaction(function () use ($created_by, $f) {
                // insert into appraisal_item_level
                $now = date("Y-m-d H:i:s");//now();
                Excel::selectSheetsByIndex(0)->load($f, function($reader) use ($now, $created_by) {

                    $sheet =  $reader->getExcel()->getSheet(0);
                    for ($i = 2; ; $i++) {
                        $item_id = $sheet->getCell('A'.$i)->getValue() ;
                        $level_id = $sheet->getCell('D'.$i)->getValue() ;

                        if ( !empty($item_id) && strlen(trim($item_id))>0 ) {
                            Log::info('['.$item_id.','.$level_id.']');
                            if(strtoupper($level_id)=='ALL'){
                                //Log::info(AppraisalService::getAppraisalLevelMaster());
                                $items = AppraisalService::getAppraisalLevelMaster();
                                foreach ($items as $item) {
                                    $count = DB::table('appraisal_item_level')
                                        ->where('item_id','=',$item_id)
                                        ->where('level_id','=',$item->level_id)
                                        /*
                                        ->where([
                                        ['item_id','=',$item_id ],
                                        ['level_id','=',$item->level_id ]
                                        ])
                                        */

                                        ->count();
                                    if($count>0){
                                        DB::table('appraisal_item_level')
                                            ->where('item_id','=',$item_id)
                                            ->where('level_id','=',$item->level_id)
                                            /*
                                            ->where([
                                                ['item_id','=',$item_id ],
                                                ['level_id','=',$item->level_id ]
                                            ])
                                            */
                                            ->update(['created_by' => $created_by,'created_dttm' => $now, 'updated_by' => $created_by, 'updated_dttm' => $now]);
                                    }else{
                                        DB::insert('insert into appraisal_item_level (item_id, level_id ,created_by,created_dttm,updated_by,updated_dttm) values (?, ?, ?, ?, ?, ?)',
                                            [$item_id, $item->level_id, $created_by, $now,$created_by, $now]);
                                    }
                                }
                            }else{
                                $count = DB::table('appraisal_item_level')
                                    ->where('item_id','=',$item_id)
                                    ->where('level_id','=',$level_id)
                                    /*
                                    ->where([
                                    ['item_id','=',$item_id ],
                                    ['level_id','=',$level_id ]
                                    ])
                                    */
                                    ->count();
                                if($count>0){
                                    DB::table('appraisal_item_level')
                                        ->where('item_id','=',$item_id)
                                        ->where('level_id','=',$level_id)
                                        /*
                                        ->where([
                                            ['item_id','=',$item_id ],
                                            ['level_id','=',$level_id ]
                                        ])
                                        */
                                        ->update(['created_by' => $created_by,'created_dttm' => $now, 'updated_by' => $created_by, 'updated_dttm' => $now]);
                                }else{
                                    DB::insert('insert into appraisal_item_level (item_id, level_id ,created_by,created_dttm,updated_by,updated_dttm) values (?, ?, ?, ?, ?, ?)',
                                        [$item_id, $level_id, $created_by, $now,$created_by, $now]);
                                }

                            }

                        }else{
                            break;
                        }
                    }
                });
                // insert into appraisal_item_org
                Excel::selectSheetsByIndex(1)->load($f, function($reader) use ($now,$created_by) {

                    $sheet =  $reader->getExcel()->getSheet(1);
                    for ($i = 2; ; $i++) {
                        $item_id = $sheet->getCell('A'.$i)->getValue() ;
                        $org_id = $sheet->getCell('D'.$i)->getValue() ;

                        if ( !empty($item_id) && strlen(trim($item_id))>0 ) {
                            Log::info('['.$item_id.','.$org_id.']');
                            if(strtoupper($org_id)=='ALL'){
                                //Log::info(AppraisalService::getOrgMaster());
                                $items = AppraisalService::getOrgMaster();
                                foreach ($items as $item) {
                                    $count = DB::table('appraisal_item_org')
                                        ->where('item_id','=',$item_id)
                                        ->where('org_id','=',$item->org_id)
                                        /*
                                        ->where([
                                        ['item_id','=',$item_id ],
                                        ['org_id','=',$item->org_id ]
                                        ])
                                        */
                                        ->count();
                                    if($count>0){
                                        DB::table('appraisal_item_org')
                                            ->where('item_id','=',$item_id)
                                            ->where('org_id','=',$item->org_id)
                                            /*
                                            ->where([
                                                ['item_id','=',$item_id ],
                                                ['org_id','=',$item->org_id ]
                                            ])
                                            */
                                            ->update(['created_by' => $created_by,'created_dttm' => $now, 'updated_by' => $created_by, 'updated_dttm' => $now]);
                                    }else{
                                        DB::insert('insert into appraisal_item_org (item_id, org_id ,created_by,created_dttm,updated_by,updated_dttm) values (?, ?, ?, ?, ?, ?)',
                                            [$item_id, $item->org_id, $created_by, $now,$created_by, $now]);
                                    }
                                }
                            }else{
                                $count = DB::table('appraisal_item_org')
                                    ->where('item_id','=',$item_id)
                                    ->where('org_id','=',$org_id)
                                    /*
                                    ->where([
                                    ['item_id','=',$item_id ],
                                    ['org_id','=',$org_id ]
                                    ])
                                    */
                                    ->count();
                                if($count>0){
                                    DB::table('appraisal_item_org')
                                        ->where('item_id','=',$item_id)
                                        ->where('org_id','=',$org_id)
                                        /*
                                        ->where([
                                            ['item_id','=',$item_id ],
                                            ['org_id','=',$org_id ]
                                        ])
                                        */
                                        ->update(['created_by' => $created_by,'created_dttm' => $now, 'updated_by' => $created_by, 'updated_dttm' => $now]);
                                }else{
                                    DB::insert('insert into appraisal_item_org (item_id, org_id ,created_by,created_dttm,updated_by,updated_dttm) values (?, ?, ?, ?, ?, ?)',
                                        [$item_id, $org_id, $created_by, $now,$created_by, $now]);
                                }
                            }
                        }else{
                            break;
                        }
                    }
                });
                // insert into appraisal_item_position
                Excel::selectSheetsByIndex(2)->load($f, function($reader) use ($now,$created_by) {

                    $sheet =  $reader->getExcel()->getSheet(2);
                    for ($i = 2; ; $i++) {
                        $item_id = $sheet->getCell('A'.$i)->getValue() ;
                        $position_id = $sheet->getCell('D'.$i)->getValue() ;

                        if ( !empty($item_id) && strlen(trim($item_id))>0 ) {
                            Log::info('['.$item_id.','.$position_id.']');
                            if(strtoupper($position_id)=='ALL'){
                                //Log::info(AppraisalService::getPositionMaster());
                                $items = AppraisalService::getPositionMaster();
                                foreach ($items as $item) {
                                    $count = DB::table('appraisal_item_position')
                                        ->where('item_id','=',$item_id)
                                        ->where('position_id','=',$item->position_id)
                                        /*
                                        ->where([
                                        ['item_id','=',$item_id ],
                                        ['position_id','=',$item->position_id ]
                                        ])
                                        */
                                        ->count();
                                    if($count>0){
                                        DB::table('appraisal_item_position')
                                            ->where('item_id','=',$item_id)
                                            ->where('position_id','=',$item->position_id)
                                            /*
                                            ->where([
                                                ['item_id','=',$item_id ],
                                                ['position_id','=',$item->position_id ]
                                            ])
                                            */
                                            ->update(['created_by' => $created_by,'created_dttm' => $now, 'updated_by' => $created_by, 'updated_dttm' => $now]);
                                    }else{
                                        DB::insert('insert into appraisal_item_position (item_id, position_id ,created_by,created_dttm,updated_by,updated_dttm) values (?, ?, ?, ?, ?, ?)',
                                            [$item_id, $item->position_id, $created_by, $now,$created_by, $now]);
                                    }
                                }
                            }else{
                                $count = DB::table('appraisal_item_position')
                                    ->where('item_id','=',$item_id)
                                    ->where('position_id','=',$position_id)
                                    /*
                                    ->where([
                                    ['item_id','=',$item_id ],
                                    ['position_id','=',$position_id ]
                                    ])
                                    */
                                    ->count();
                                if($count>0){
                                    DB::table('appraisal_item_position')
                                        ->where('item_id','=',$item_id)
                                        ->where('position_id','=',$position_id)
                                        /*
                                        ->where([
                                            ['item_id','=',$item_id ],
                                            ['position_id','=',$position_id ]
                                        ])
                                        */
                                        ->update(['created_by' => $created_by,'created_dttm' => $now, 'updated_by' => $created_by, 'updated_dttm' => $now]);
                                }else{
                                    DB::insert('insert into appraisal_item_position (item_id, position_id ,created_by,created_dttm,updated_by,updated_dttm) values (?, ?, ?, ?, ?, ?)',
                                        [$item_id, $position_id, $created_by, $now,$created_by, $now]);
                                }
                            }
                        }else{
                            break;
                        }
                    }
                });
            });
        }
    }
}