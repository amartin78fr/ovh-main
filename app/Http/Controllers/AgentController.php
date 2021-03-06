<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Traits\AgentTrait;
use App\Validators\AgentValidator;
use App\Traits\ResponseTrait;
use Auth;
use App\Agent;
use App\AgentSchedule;
use App\Helpers\Helper;
use Session;
use App\Mission;
use App\Feedback;
use App\Report;
use App\MissionRequestsIgnored;
use Carbon\Carbon;
use App\Traits\MissionTrait;
use App\Helpers\PlivoSms;

class AgentController extends Controller
{
	use AgentValidator, AgentTrait, ResponseTrait, MissionTrait;
    
    /**
     * @param $request
     * @return mixed
     * @method index
     * @purpose Load agent signup view 
     */
    public function index(){
        return view('agent-register');
    }

    /** 
     * @param $request
     * @return mixed
     * @method agentRegister
     * @purpose To register as an agent
     */
    public function signup(Request $request){
    	try{
            // Check Agent Table Validation
            $validation = $this->agentSignupValidations($request);
        
            if($validation['status']==false){
                return response($this->getValidationsErrors($validation));
            }
          
            $agentType = json_decode($request->agent_type);
            $dog = 0;
            if(empty($agentType)){
                return $this->getErrorResponse(trans('messages.choose_agent'));
            }else{
                $cnaps = 0;
                foreach($agentType as $type){
                    if($type > 3){
                        $cnaps = 1;
                    }
                    if($type==6){
                        $dog = 1;
                    }
                }
                if($cnaps == 1){
                    if(empty(trim($request->cnaps_number))){
                        return $this->getErrorResponse(trans('messages.enter_cnaps'));
                    }
                }
                if($dog == 1){
                    if(empty(trim($request->dog_info))){
                        return $this->getErrorResponse(trans('messages.add_dog_info'));
                    }
                }
            }
            if(!isset($request->work_location['lat']) || empty($request->work_location['lat'])){
                return $this->getErrorResponse(trans('messages.gps_disable'));
            }
            // Works on HTTPS
            // if(!isset($request->current_location['lat']) || empty($request->current_location['lat'])){
            //     return $this->getErrorResponse('GPS location is not enabled.');
            // }
            return $this->registerAgent($request);
        }catch(\Exception $e){
            return response($this->getErrorResponse($e->getMessage()));
        }
    }

    /**
     * @param $request
     * @return mixed
     * @method showAvailableAgents
     * @purpose Show available agents on map
     */
	
	public function showAvailableAgentSecurityPatrol(){
		if(Session::has('mission')){
			$returnData = array('status'=>1,'data'=>Session::get('mission'));
		}else{
			$returnData = array('status'=>0,'data'=>array());
		}
		return response()->json($returnData);
	}
	
    public function showAvailableAgents(Request $request){
        $latitude = '48.7993';
        $longitude = '1.7153';
        $location = 'France';
        $zoom = 7;
        $searchVal = false;
        if(isset($request->latitude) && isset($request->longitude)){
            $latitude = $request->latitude;
            $longitude = $request->longitude;
            $location = $request->location;
            $searchVal = true;
            $zoom = 11;
        }else{
            if(Session::has('mission')){
                Session::forget('mission');
            }
            $request->request->set('latitude',$latitude);
            $request->request->set('longitude',$longitude);
        }
        $search['latitude'] = $latitude;
        $search['longitude'] = $longitude;
        $search['location'] = $location;
        $search['s_val'] = $searchVal; 
        $search['zoom'] = $zoom;
        $agents = $this->getAvailableAgents($request);
        // $this->print($agents);
        return view('available_agents',['data'=>json_encode($agents),'search'=>$search]);
    }

    /**
     * @param $request
     * @return mixed
     * @method agentProfileView
     * @purpose Load agent profile view 
     */
    public function agentProfileView(){
        $profile = Agent::select('first_name','last_name','phone','image','home_address')->where('user_id',\Auth::id())->first()->toArray();
        $data['profile'] = $profile;
        return view('agent.profile',$data);
    }
	
	
	public function reportFilter(){
		
		$agent = array();
		$agentDatas = Agent::select('id','first_name','last_name')->where('status','1')->get();
		foreach($agentDatas as $agentData){
			$agent[$agentData->id] = $agentData->first_name.' '.$agentData->last_name;
		}
    	return view('agent.report-pdf')->with('agent',$agent);
    }
	
	public function reportFilterPost(Request $request){
		$inputData = $request->all();
		$mission = Mission::where('agent_id','!=','0')->where(function ($query) {
								$query->where('payment_status',1)
									  ->orWhere('payment_status',2);
							});
		if($request->from_date){
			$to_date = Carbon::now()->format('yy-m-d');
			if($request->to_date){
				$to_date = Carbon::parse($request->to_date)->format('yy-m-d');
			}
			$mission = $mission->whereBetween('created_at',[Carbon::parse($request->from_date)->format('yy-m-d'), $to_date]);
		}
		$result = $mission->get();
		$customPaper = array(0,0,500.00,850.80);
		$pdf = \PDF::loadView('pdf.special_agent_report', ['results'=>$result])->setPaper($customPaper, 'landscape');
		return $pdf->download('report.pdf');
	}
	
	/**
     * @param $request
     * @return new feature page
     * @method new-feature
     * @purpose after complete ride
     */
	public function report($mission_id){
		$newFeature = array();
		$newFeatureData = Report::where('mission_id',Helper::decrypt($mission_id))->first();
		$number = Report::select('id')->orderBy('id', 'DESC')->first();
		$numberData = ($number) ? $number->id : 0;
		if($newFeatureData){
			$newFeature = $newFeatureData->toArray();
		}
		$report_id = 'RIA'.sprintf("%03d", $numberData).date("dmY");
        return view('agent.report')->with('report_id',$report_id)->with('mission_id',$mission_id)->with('newFeature',$newFeature);
    }
	
	public function reportView($mission_id){
		try{
			$feature = Report::where('mission_id',Helper::decrypt($mission_id))->first();
			return view('agent.report-view')->with('feature',$feature);
		}catch(\Exception $e){
			return redirect('agent/missions');
        }
    }
	
	public function reportUpdate($mission_id, Request $request) {
		
		// NewFeature
		$inputDate = $request->all();
		
		$validation = $this->agentNewFeatureValidations($request);
        
		if($validation['status']==false){
			return response($this->getValidationsErrors($validation));
		}
			
		unset($inputDate['_token']);
		$inputDate['mission_id'] = Helper::decrypt($mission_id);
		$inputDate['date'] = Carbon::parse($request->date)->format('yy-m-d');
		$inputDate['status'] = 1;
		$report_id = Report::updateOrCreate(array('mission_id'=>$inputDate['mission_id']),$inputDate);
		$response['message'] = trans('messages.new_feature_save');
		$response['delayTime'] = 2000;
		$response['modelShow'] = true;
		$response['report_id'] = $report_id;
		// $response['url'] = url('agent/missions');
		return response($this->getSuccessResponse($response));
	}
	
	
	public function signatureUpdate($mission_id, Request $request) {
		
		$validation = $this->agentSignatureValidations($request);
        
		if($validation['status']==false){
			return response($this->getValidationsErrors($validation));
		}
		$folderPath = public_path('agent/signature/');
		$image_parts = explode(";base64,", $request->signature);
		$image_type_aux = explode("image/", $image_parts[0]);
		$image_type = $image_type_aux[1];
		$image_name = uniqid() . '.'.$image_type;
		$image_base64 = base64_decode($image_parts[1]);
		$file = $folderPath . $image_name;
		file_put_contents($file, $image_base64);
		$mission_id = Helper::decrypt($mission_id);
		Report::where('mission_id',$mission_id)->update(array('signature'=>$image_name));
		$response['message'] = trans('messages.new_feature_save');
		$response['delayTime'] = 2000;
		$response['success']  = true;
        $response['error']    = false;
		$response['modelShow'] = true;
		$response['url'] = url('agent/missions');		
		return response($response);
	}

    /**
     * @param $request
     * @return mixed
     * @method setAvailability
     * @purpose Set agent availability status
     */
    public function setAvailability(Request $request){
        try{
            if(Auth::check() && Auth::user()->role_id==2){
                $data = Agent::where('user_id',Auth::user()->id)->first();
                if($data->available==2){
                    return response($this->getErrorResponse(trans('messages.cant_change_availability')));
                }else{
                    $availableStatus = $request->availability_status;
                    $update = Agent::where('user_id',Auth::user()->id)->update(['available'=>$availableStatus]);
                    if($update){
                        $response['message'] = trans('messages.availability_changed');
                        $response['delayTime'] = 5000;
                        $response['url'] = $request->current_url;
                        return response($this->getSuccessResponse($response));
                    }else{
                        return response($this->getErrorResponse(trans('messages.error')));
                    }
                }
            }else{
                return response($this->getErrorResponse(trans('messages.error')));    
            }
        }catch(\Exception $e){
            return response($this->getErrorResponse($e->getMessage()));
        }
    }

    /**
     * @param $request
     * @return mixed
     * @method viewAgentDetails
     * @purpose View agent details
     */
    public function viewAgentDetails($agent_id,$distance){
        $agent_id = Helper::decrypt($agent_id);
		$feedback = Feedback::where('agent_id',$agent_id);
		$rating = Helper::agent_rating($feedback->get()->toArray());
        $agent = Agent::where('id',$agent_id)->first();
        return view('view-agent-details',['agent'=>$agent,'distance'=>$distance,'rating'=>$rating,'feedbacks'=>$feedback->get()]);
    }

    /**
     * @param $request
     * @return mixed
     * @method setScheduleView
     * @purpose Set Agent Schedule
     */
    public function setScheduleView($agent_id){
        $agent_id = Helper::decrypt($agent_id);
        $agent = Agent::where('id',$agent_id)->first();
        $schedule = AgentSchedule::select('schedule_date','available_from','available_to')->where('agent_id',$agent_id)->whereDate('schedule_date', '>=', Carbon::now())->get();
        return view('agent.schedule',['agent'=>$agent,'schedule'=>$schedule]);
    }

    /**
     * @param $request
     * @return mixed
     * @method saveSchedule
     * @purpose Save Schedule
     */
    public function saveSchedule(Request $request){
		
		$validation = $this->agentScheduleValidations($request);
		if($validation['status']==false){
			return response($this->getValidationsErrors($validation));
		}
		
        $post = array_except($request->all(),'_token');
        $agent_id = Auth::user()->agent_info->id;
        $schedule_date = Carbon::createFromFormat('d/m/Y', $request->schedule_date)->format('Y-m-d');
        $post['schedule_date'] = $schedule_date;
        $isExists = AgentSchedule::where('agent_id',$agent_id)->whereDate('schedule_date',$schedule_date)->count();
        if($isExists!=0){
            $result = AgentSchedule::where('agent_id',$agent_id)->whereDate('schedule_date',$schedule_date)->update($post);
        }else{
            $post['agent_id'] = $agent_id;
            $post['created_at'] = Carbon::now();
            $post['updated_at'] = Carbon::now();
            $result = AgentSchedule::insert($post);
        }
        if($result){
            $response['message'] = trans('messages.schedule_saved');
            $response['delayTime'] = 2000;
            $response['url'] = url('agent/schedule/'.Helper::encrypt($agent_id));
            return response($this->getSuccessResponse($response));
        }else{
            return response($this->getErrorResponse(trans('messages.error')));
        }
    }

    /**
     * @param $request
     * @return mixed
     * @method agentSubMissions
     * @purpose Agent Sub Missions
     */
    public function agentSubMissions(Request $request){
        try{
            $mission_id = Helper::decrypt($request->mission_id);
            $mission = Mission::where('id',$mission_id)->first();
            $total_hours = $mission->total_hours;
            $multiple = floor($total_hours/12);
            $remainder = $total_hours-$multiple*12;
            for($i=1; $i<=$multiple; $i++){
                $hours[] = 12;
            }
            if($remainder!=0){
                $hours[] = $remainder;
            }
            $data = $mission->toArray();
            $data = array_except($data,['id','created_at','updated_at','total_hours']);
            $time = Carbon::now();
            $x=0;
            $subMissions = [];
            foreach ($hours as $key => $value) {
                $x++;
                $data['status'] = 3;
                if($x!=1){
                    $data['agent_id'] = 0;
                    $data['status'] = 0;
                    $time = $new_time;
                }
                $data['start_date_time'] = $time;
                $data['total_hours'] = $value;
                $data['created_at'] = Carbon::now();
                $data['updated_at'] = Carbon::now();
                $data['parent_id'] = $mission->id;
                $new_time = date("Y-m-d H:i:s", strtotime('+'.$value.' hours', strtotime($time)));
                $subMissions[] = $data;
            }
            $result = Mission::insert($subMissions);
            if($result){
                Mission::where('id',$mission_id)->update(['agent_id'=>0]);
                
                /*----Customer send phone notification-----*/
                    PlivoSms::sendSms(['phoneNumber' => $customerNumber, 'msg' => 'Mission id  "'.$mission_id.'" is accepted by agent, for more details please login into https://www.ontimebe.com' ]);
                /*--------------*/
                    
                $response['message'] = trans('messages.mission_accepted_12');
                $response['delayTime'] = 2000;
                $response['modelhide'] = '#mission_action';
                $response['url'] = url('agent/mission-requests');
                return response($this->getSuccessResponse($response));
            }else{
                return response($this->getErrorResponse(trans('messages.error')));
            }
        }catch(\Plivo\Exceptions\PlivoResponseException $e){
                $response['message'] = trans('messages.mission_accepted_12');
                $response['delayTime'] = 2000;
                $response['modelhide'] = '#mission_action';
                $response['url'] = url('agent/mission-requests');
                return response($this->getSuccessResponse($response));
        }catch(\Exception $e){
            return response($this->getErrorResponse($e->getMessage()));
        }
    }

    /**
     * @param $request
     * @return mixed
     * @method missionExpiredRequest
     * @purpose Agent mission expired
     */
    public function missionExpiredRequest(Request $request){
        $mission_id = Helper::decrypt($request->record_id);
        // Check if mission request is expired or not
        $mission_expired = $this->missionExpired($mission_id);
        if($mission_expired==1){
            $response = $this->getErrorResponse(trans('messages.mission_expired'));
            $response['url'] = url('agent/mission-requests');
            return response($response);
        }
    }

    /**
     * @param $request
     * @return mixed
     * @method removeExpiredMission
     * @purpose Remove expired mission
     */
    public function removeExpiredMission($id){
        try{
            $id = Helper::decrypt($id);
            $update = MissionRequestsIgnored::where('id',$id)->update(['is_deleted'=>1]);
            if($update){
                $response['message'] = trans('messages.mission_req_deleted');
                $response['url'] = url('agent/mission-requests'); 
                return $this->getSuccessResponse($response);
            }else{
                return $this->getErrorResponse(trans('messages.error'));
            }
        }catch(\Exception $e){
            return $this->getErrorResponse($e->getMessage());
        }
    }

}
