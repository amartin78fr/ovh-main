<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Validators\OperatorValidator;
use App\Traits\ResponseTrait;
use App\Traits\HelperTrait;
use App\Traits\PaymentTrait;
use Auth;
use App\Agent;
use App\Report;
use App\Operator;
use App\MessageCenter;
use App\User;
use App\Customer;
use App\CustomRequest;
use App\Mission;
use App\Helpers\Helper;
use Hash;
use DB;
use App\Notifications\MissionCreated;
use App\Notifications\PaymentDone;
use App\Notifications\AgentCreated;
use Carbon\Carbon;
use App\UserPaymentHistory;
use App\PaymentApproval;
use App\RefundRequest;
use App\Helpers\PlivoSms;
use Session;
use Redirect;

class OperatorController extends Controller
{

	use OperatorValidator, ResponseTrait, PaymentTrait;

    public $limit;

    public function __construct(){
        $this->limit = 10;
    }
    
    /**
     * @return mixed
     * @method loadDashboardView
     * @purpose Load dashboard view
     */
    public function loadProfileView(){
        $profile = '';
        $profile = Operator::select('first_name','last_name','phone','image','home_address')->where('user_id',\Auth::id())->first();
        if($profile){
            $profile = $profile->toArray();
        }
        $data['profile'] = $profile;
    	return view('operator.profile',$data);
    }

    /**
     * @return mixed
     * @method viewAgentsList
     * @purpose View Agents List
     */
    public function viewAgentsList(Request $request){  
        $pendingAgents = Agent::where('status',0)->orderBy('id','DESC')->paginate($this->limit,['*'],'pending');
        $verifiedAgents = Agent::where('status','!=','')->whereIn('status',[1,3])->orderBy('id','DESC')->paginate($this->limit,['*'],'verified');
        $params = [
            'pending_agents' => $pendingAgents,
            'verified_agents' => $verifiedAgents,
            'page_no' => 1,
            'page_name' => 'pending',
            'limit' => $this->limit
        ];
        if($request->isMethod('get')){
            if(!empty($request->all())){
                $pageName = array_keys($request->all());
                $pageNo = array_values($request->all());
                $params['page_no'] = $pageNo[0]; 
                $params['page_name'] = $pageName[0];
            }
        } 
        return view('operator.agents_list',$params);
    }
	
	public function reportPdf($agent_id){
		$agent_id = Helper::decrypt($agent_id);
		$agent = Agent::whereId($agent_id)->first();
		$result = Mission::where('agent_id',$agent_id)->where(function ($query) {
								$query->where('payment_status',1)
									  ->orWhere('payment_status',2);
							})->get();
		$customPaper = array(0,0,500.00,850.80);
        $pdf = \PDF::loadView('pdf.agent_report', ['results'=>$result,'agent'=>$agent])->setPaper($customPaper, 'landscape');
        return $pdf->download('report.pdf');
    }

    /**
     * @return mixed
     * @method viewAgentDetails
     * @purpose View details of agents
     */
    public function viewAgentDetails($en_id){
        $id = Helper::decrypt($en_id);
        $agent = Agent::where('id',$id)->first();
        return view('operator.agent_details',['data'=>$agent]);
    }

    public function deleteAgentDetails($e_id){
        $id = Helper::decrypt($e_id);
        Agent::where('id',$id)->update(['status'=>3]);
        return redirect()->back()->with('message_success', 'Deleted Successfully.');
    }

    /**
     * @return mixed
     * @method agentVerificationAction
     * @purpose To process agent verification
     */
    public function agentVerificationAction(Request $request){
        $status = $request->verify_status;
        $user_id = Helper::decrypt($request->user_id);
        $result = Agent::where('user_id',$user_id)->update(['status'=>$status]);
        if($result){
            $password1 = Helper::generateToken(8);
            $password = Hash::make($password1);
            User::where('id',$user_id)->update(['password'=>$password]);
            $user = User::where('id',$user_id)->first();
            if($status==1){
                $message = trans('messages.agent_verification_message',['email'=>$user->email,'password'=>$password1]);
            }else{
                $message = trans('messages.agent_verification_rejected');
            }
            /*----Agent Register Confirmation-----*/
            $mailContent = [
                'name' => ucfirst($user->agent_info->first_name),
                'message' => $message, 
            ];
            $user->notify(new AgentCreated($mailContent));    
            /*--------------*/
            $response['message'] = trans('messages.agent_verified');
            $response['delayTime'] = 2000;
            $response['url'] = url('operator/agents');
            return $this->getSuccessResponse($response);
        }else{
            return response($this->getErrorResponse(trans('messages.error')));
        }
    }

    /**
     * @return mixed
     * @method viewCustomersList
     * @purpose Load customer list view
     */
    public function viewCustomersList(Request $request){
        $customers = Customer::select('customers.*','users.email')->join('users','users.id','customers.user_id')->orderBy('customers.id','DESC')->where('customers.status','!=',3)->paginate($this->limit);
        $params = [
            'data' => $customers,
            'limit' => $this->limit,
            'page_no' => 1
        ];
        if(isset($request->page)){
            $params['page_no'] = $request->page; 
        }
   
        return view('operator.customers_list',$params);
    }
	
	
	/**
     * @return mixed
     * @method missionRequest
     * @purpose Load customer list view
     */
    public function missionRequest(Request $request){
        $custom_req = CustomRequest::select('customers.first_name','customers.last_name','custom_requests.title','custom_requests.location')
					->join('customers','customers.id','custom_requests.customer_id')->orderBy('custom_requests.id','DESC')->paginate($this->limit);
        $params = [
            'data' => $custom_req,
            'limit' => $this->limit,
            'page_no' => 1
        ];
        if(isset($request->page)){
            $params['page_no'] = $request->page; 
        }
        return view('operator.mission_request',$params);
    }

    /**
     * @return mixed
     * @method viewCustomerDetails
     * @purpose View details of customer
     */
    public function viewCustomerDetails($en_id){
        $id = Helper::decrypt($en_id);
        $customer = Customer::where('id',$id)->first();
        return view('operator.customer_details',['data'=>$customer]);
    }
	
	public function customer_status(Request $request){
		if($request->status == 'invouce'){
			Mission::where('id',$request->id)->update(array('invoice_status'=>$request->bank_transfer));
		}else{
			if($request->id){
				$cus = Customer::find($request->id);
				if($cus){
					Customer::where('id',$request->id)->update(array('add_bank'=>$request->bank_transfer));
				}
			}
		}
    }

    public function deleteCustomerDetails($e_id){ 
        $id = Helper::decrypt($e_id);
        Customer::where('id',$id)->update(['status'=>3]);
        return redirect()->back()->with('message_success', 'Deleted Successfully.');
    }

    /**
     * @return mixed
     * @method agentVerificationAction
     * @purpose To process agent verification
     */
    // public function customerVerificationAction(Request $request){
    //     $status = $request->verify_status;
    //     $user_id = Helper::decrypt($request->user_id);
    //     $result = Customer::where('user_id',$user_id)->update(['status'=>$status]);
    //     if($result){
    //         $password1 = Helper::generateToken(8);
    //         $password = Hash::make($password1);
    //         User::where('id',$user_id)->update(['password'=>$password]);
    //         $user = User::where('id',$user_id)->first();
    //         if($status==1){
    //             $message = "<b>Congratulations</b><br>Your profile verification is completed successfully and your details are approved.<br><br>Your login credentials are:<br><br>Email: ".$user->email."<br> Password: ".$password1;
    //         }else{
    //             $message = "Your profile verification is completed successfully and your details are rejected.<br><br>Thanks";
    //         }
    //         $templateName = 'emails.general';
    //         $data['message'] = $message;
    //         $toEmail = $user->email;
    //         $toName = $user->email;
    //         $subject = "Customer Verification";
    //         Helper::sendCommonMail($templateName,$data,$toEmail,$toName,$subject);
    //         $response['message'] = 'Customer verification completed successfully.';
    //         $response['delayTime'] = 2000;
    //         $response['url'] = url('operator/customers/pending');
    //         return $this->getSuccessResponse($response);
    //     }else{
    //         return response($this->getErrorResponse('Something went wrong. Try again later !'));
    //     }
    // }

    /**
     * @return mixed
     * @method missionsList
     * @purpose To get all missions list
     */

    public function missionsList(Request $request){
        $statusCond = [];
        $missionArchived = [];
        $missionAll = [];
        $missionFuture = [];
        $missionQuick = [];
        $missionCompleted = [];
        $statusArr = [];
        
        if($request->get('archived')){
            $missionArchived = Mission::with('child_missions')->where('parent_id',0)->where('status',10)->orderBy('id','DESC')->paginate($this->limit,['*'],'all');

        }else{
            $missionStatus = $request->get('missionStatus'); 
            if($missionStatus !== null && $missionStatus !== 'all'){
                $statusCond = ['status'=>$missionStatus];
            }
			
			$mission = Mission::with(['child_missions','customer_details']);
			
			if($request->get('search') && !empty($request->get('search'))){
				$searchString = $request->get('search');
				$search = $mission->whereHas('customer_details',function ($query) use ($searchString){
					$query->where(DB::raw("CONCAT(`first_name`, ' ', `last_name`)"), 'like', '%'.$searchString.'%');
				});
			}

            $missionAll = $mission->where('parent_id',0)->where('status','!=',10)->where($statusCond)->orderBy('id','DESC')->paginate($this->limit,['*'],'all');
            $missionFuture = $mission->where('quick_book',0)->where('parent_id',0)->where('status','!=',10)->where('start_date_time','>=',Carbon::now())->where($statusCond)->orderBy('id','DESC')->paginate($this->limit,['*'],'future');
            $missionQuick = $mission->where('quick_book',1)->where('parent_id',0)->where('status','!=',10)->where($statusCond)->orderBy('id','DESC')->paginate($this->limit,['*'],'quick');
            $missionCompleted = Mission::with(['child_missions','customer_details'])->where('parent_id',0)->where('status',5)->where('status','!=',10)->where($statusCond)->orderBy('id','DESC')->paginate($this->limit,['*'],'finished');        
            $statusArr = Helper::getMissionStatus();
            $statusArr = array_flip($statusArr);
            
        }
        
        $params = [
            'archived_mission' => $missionArchived,
            'mission_all' => $missionAll,
            'future_mission' => $missionFuture,
            'quick_mission' => $missionQuick,
            'finished_mission' => $missionCompleted,
            'status_list'=>$statusArr,
            'limit' => $this->limit,
            'page_no' => 1,
			'paginate_array' => array(),
            'page_name' => 'all'
        ];
        
        if($request->isMethod('get')){
            if(isset($request->all)){ 
                $params['page_no'] = $request->all; 
                $params['page_name'] = 'all'; 
            }
            if(isset($request->future)){ 
                $params['page_no'] = $request->future; 
                $params['page_name'] = 'future'; 
            }
            if(isset($request->quick)){ 
                $params['page_no'] = $request->quick; 
                $params['page_name'] = 'quick'; 
            }
            if(isset($request->finished)){ 
                $params['page_no'] = $request->finished; 
                $params['page_name'] = 'finished';
            }
            if(isset($request->archived)){ 
                $params['page_no'] = $request->archived; 
                $params['page_name'] = 'archived';
            }
			if($request->missionStatus){
				$params['paginate_array'] = array('missionStatus'=>$request->missionStatus);
			}
			if($request->search){
				$params['paginate_array'] = array_merge($params['paginate_array'],array('search'=>$request->search));
			}
        }
        return view('operator.missions',$params);
    }


    /**
     * @param $mission_id
     * @return mixed
     * @method viewMissionDetails
     * @purpose View mission details
     */
    public function viewMissionDetails($mission_id){
        $mission_id = Helper::decrypt($mission_id);
        $data['mission'] = Mission::where('id',$mission_id)->first();
		
		// print_r($data['mission']->upload_invoice);
		// die();
        return view('operator.view_mission_details',$data);
    }

    /**
     * @return mixed
     * @method verifyMission
     * @purpose To verify a mission
     */
    public function verifyMission(Request $request, $id){
        $id = Helper::decrypt($id);
        $mission = Mission::where('id',$id)->first();
        return view('operator.verify_mission',['data'=>$mission]);
    }

    /**
     * @param $mission_id
     * @return mixed
     * @method assignMissionAgent
     * @purpose Assign mission agent
     */
    public function assignMissionAgent($mission_id){
        $mission_id = Helper::decrypt($mission_id);
		$mission = Mission::where('id',$mission_id)->first();
        $data['mission'] = $mission;
        //Calculate mission start and end times 
        $add_mission_hours = '+'.$mission->total_hours.' hours';
        $mission_start_date_time = $mission->start_date_time;
        $mission_end_date_time = date('Y-m-d H:i:s', strtotime($add_mission_hours, strtotime($mission->start_date_time)));
        $mission_start_time = date('H:i:s',strtotime($mission_start_date_time));
        $mission_end_time = date('H:i:s',strtotime($mission_end_date_time));
        // Check if any agent available 
        $agent_type_needed = $mission->agent_type;
        if($mission->quick_book==1){
            $start_date = date('Y-m-d');    
            if(isset($mission->start_date_time) && $mission->start_date_time!=''){
                $start_date = date('Y-m-d',strtotime($mission->start_date_time));
            }
        }else{
            $start_date = date('Y-m-d',strtotime($mission->start_date_time));
        }
        // Get nearest agent
        $a = Agent::whereHas('types',function($q) use($agent_type_needed){
            $q->where('agent_type',$agent_type_needed);
        });
        $a->with(['schedule'=>function($q) use ($start_date,$mission_start_time,$mission_end_time){
            $q->whereDate('schedule_date',$start_date)
            ->whereTime('available_from','<=',$mission_start_time)
            ->whereTime('available_to','>=',$mission_end_time);
        }]);
        if($mission->quick_book==0 || (isset($mission->start_date_time) && $mission->start_date_time!='')){
            $a->whereHas('schedule',function($q) use ($start_date,$mission_start_time,$mission_end_time){
                $q->whereDate('schedule_date',$start_date)
                ->whereTime('available_from','<=',$mission_start_time)
                ->whereTime('available_to','>=',$mission_end_time);
            });
            $a->whereDoesntHave('missions',function($q) use ($mission_start_date_time,$mission_end_date_time){
                $q->whereBetween('start_date_time',[$mission_start_date_time,$mission_end_date_time]);
            });
        }
		
		$verifiedAgents = Agent::select('id','first_name','last_name','username')->where('status','!=','')->where('status',1)->orderBy('id','DESC')->get();
        $agents = $a->where('status',1)->where('available','!=',0)->select(DB::raw("*, 111.111 *
                DEGREES(ACOS(LEAST(1.0, COS(RADIANS(".$mission->latitude."))
                * COS(RADIANS(work_location_latitude))
                * COS(RADIANS(".$mission->longitude." - work_location_longitude))
                + SIN(RADIANS(".$mission->latitude."))
                * SIN(RADIANS(work_location_latitude))))) AS distance_in_km"))->having('distance_in_km', '<', 100)->orderBy('distance_in_km','ASC')->get();  
        $data['verifiedAgents'] = $verifiedAgents;
		$data['agents'] = $agents;
        return view('operator.assign_agent',$data);
    }

    public function bookAgentLaterMission(Request $request){
        try{
            $mission_id = Helper::decrypt($request->mission_id);
            $agent_id = Helper::decrypt($request->agent_id);
            Mission::where('id',$mission_id)->update(['agent_id'=>$agent_id,'assigned_at'=>Carbon::now()]);
            $mission = Mission::where('id',$mission_id)->first();
            /*----Agent Notification-----*/
            if(isset($mission->agent_details)){
				try {
					$cus_name = $mission->customer_details->first_name.' '.$mission->customer_details->last_name;
					$message = trans('dashboard.report.received_a_new_mission')." \n";
					$message .= trans('dashboard.report.customer_name').$cus_name."\n";
					$message .= trans('dashboard.report.mission_type').trans('dashboard.agents.'.$mission->intervention.'')."\n";
					$message .= trans('dashboard.report.location').$mission->location;
					PlivoSms::sendSms(['phoneNumber' => $mission->agent_details->phone, 'msg' => trans($message) ]);
				}catch(\Exception $e){
				}
                $mailContent = [
                    'name' => ucfirst($mission->agent_details->first_name),
                    'message' => trans('messages.agent_new_mission_notification'), 
                    'url' => url('agent/mission-details/view').'/'.$request->mission_id 
                ];
                $mission->agent_details->user->notify(new MissionCreated($mailContent));
            }
            /*--------------*/
            $response['message'] = trans('messages.mission_req_sent');
            $response['delayTime'] = 2000;
            $response['url'] = url('operator/missions');
            return $this->getSuccessResponse($response);
        }catch(\Exception $e){
            return response($this->getErrorResponse($e->getMessage()));
        }
    }
	
	public function reportView($mission_id){
		$feature = Report::where('mission_id',Helper::decrypt($mission_id))->first();
        return view('operator.report-view')->with('feature',$feature);
    }

    public function createSubMissions($mission_id){
        $mission_id = Helper::decrypt($mission_id);
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
        if(isset($mission->start_date_time)){
            $time = $mission->start_date_time;
        }else{
            $time = Carbon::now();
        }
        $x=0;
        foreach ($hours as $key => $value) {
            $x++;
            if($x!=1){
                $time = $new_time;
            }
            $data['start_date_time'] = $time;
            $data['total_hours'] = $value;
            $data['created_at'] = Carbon::now();
            $data['updated_at'] = Carbon::now();
            $data['parent_id'] = $mission->id;
            $new_time = date("Y-m-d H:i:s", strtotime('+'.$value.' hours', strtotime($time)));
            Mission::insert($data);
        }
        return redirect('operator/missions');
    }

    /**
     * @param $request
     * @return mixed
     * @method getPaymentHistory
     * @purpose Get payment history
     */
    public function getPaymentHistory(Request $request){
        $data = UserPaymentHistory::orderBy('id','DESC')->paginate($this->limit);
        $params = [
            'history' => $data,
            'limit' => $this->limit,
            'page_no' => 1
        ];
        if(isset($request->page)){
            $params['page_no'] = $request->page; 
        }
        return view('operator.billing',$params);
    }

    /**
     * @param $request
     * @return mixed
     * @method paymentApprovalsView
     * @purpose View list of payment approvals
     */
    public function paymentApprovalsView(Request $request){
        $data = PaymentApproval::where('status',0)->orderBy('id','DESC')->paginate($this->limit);
        $params = [
            'payments' => $data,
            'limit' => $this->limit,
            'page_no' => 1
        ];
        if(isset($request->page)){
            $params['page_no'] = $request->page; 
        }
        return view('operator.payment_approval',$params);
    }

    /**
     * @param $request
     * @return mixed
     * @method paymentApprovalAction
     * @purpose View list of payment approvals
     */
    public function paymentApprovalAction(Request $request){
        try{
            $record_id = Helper::decrypt($request->record_id);
            $type = $request->type;
            $data = PaymentApproval::where('id',$record_id)->first();
            if($type==1){
                $chargeData = [
                    'customer' => $data->customer_details->customer_stripe_id,
                    'currency' => config('services.stripe.currency'),
                    'amount'   => $data->amount,
                    'description' => 'Extra Mission Charge Amount',
                ];
                $charge = $this->createCharge($chargeData);
                $responseData = [
                    'customer_id'   => $data->customer_id,
                    'mission_id'    => $data->mission_id,
                    'amount'        => $data->amount,
                    'status'        => $charge['status'],
                    'created_at'    => Carbon::now(),
                    'updated_at'    => Carbon::now()
                ];
                if($charge['status']=='succeeded'){
                    // Save data to payment history
                    $responseData['charge_id'] = $charge['id'];
                    $result = UserPaymentHistory::insert($responseData);
                    if($result){
                        PaymentApproval::where('id',$record_id)->update(['status'=>1]);
                        /*----Payment Notification-----*/
                        $mailContent = [
                            'name' => ucfirst($data->customer_details->first_name),
                            'message' => trans('messages.payment_done_message',['amount'=>$data->amount]), 
                            'url' => url('customer/billing-details') 
                        ];
                        $data->customer_details->user->notify(new PaymentDone($mailContent));
                        /*--------------*/
                        $response['message'] = trans('messages.payment_completed');
                        $response['delayTime'] = 2000;
                        $response['url'] = url('operator/payment-approvals');
                        return $this->getSuccessResponse($response);
                    }else{
                        $response['message'] = trans('messages.error');
                        $response['delayTime'] = 2000;
                        $response['url'] = url('operator/payment-approvals');
                        return $this->getErrorResponse($response);
                    }
                }else{
                    // Store Failed Payment 
                    $responseData = [ 
                        'remarks' => 'Extra Mission Amount', 
                        'response' => json_encode($charge),
                    ];
                    FailedPayment::insert($responseData);

                    $response['message'] = trans('messages.payment_failed');
                    $response['delayTime'] = 2000;
                    $response['url'] = url('operator/payment-approvals');
                    return $this->getErrorResponse($response);
                }
            }else{
                PaymentApproval::where('id',$record_id)->update(['status'=>2]);
                $response['message'] = trans('messages.payment_rejected');
                $response['delayTime'] = 2000;
                $response['url'] = url('operator/payment-approvals');
                return $this->getSuccessResponse($response);
            }

        }catch(\Exception $e){
			if($e->getMessage() == 'Cannot charge a customer that has no active card'){
				return $this->getErrorResponse(trans("messages.cannot_charge_a_customer"));
			}
            return response($this->getErrorResponse($e->getMessage()));
        }
    }

    /**
     * @return mixed
     * @method missionsListWithoutAgents
     * @purpose To get all missions list without agents
     */
    public function missionsListWithoutAgents(Request $request){
        $missions = Mission::whereDoesntHave('child_missions')->where('status',0)->where('agent_id',0)->where(function ($query) {
					$query->where('payment_status',1)->orWhere('payment_status',2);
				})->orderBy('id','DESC')->paginate($this->limit); 
        $statusArr = Helper::getMissionStatus();
        $params = [
            'data' => $missions,
            'status_list'=>$statusArr,
            'limit' => $this->limit,
            'page_no' => 1
        ];
        if(isset($request->page)){
            $params['page_no'] = $request->page; 
        }
        return view('operator.missions_without_agents',$params);
    }

    /**
     * @return mixed
     * @method refundRequestsView
     * @purpose Refund Requests
     */
    public function refundRequestsView(Request $request){
        $refunds = RefundRequest::orderBy('id','DESC')->paginate($this->limit); 
        $params = [
            'refunds' => $refunds,
            'limit' => $this->limit,
            'page_no' => 1
        ];
        if(isset($request->page)){
            $params['page_no'] = $request->page; 
        }
        return view('operator.refund_requests',$params);
    }
	
	public function messageCenter(Request $request){
		$messageCenter = MessageCenter::select('message_centers.id','message_centers.user_id as user_id','message_centers.message_type','message_centers.status','message_centers.created_at','customers.first_name','customers.last_name','agents.first_name as a_first_name','agents.last_name as a_last_name','users.email')
						->leftJoin('customers','customers.user_id','message_centers.user_id')
						->leftJoin('agents','agents.user_id','message_centers.user_id')
						->join('users','users.id','message_centers.user_id')->distinct()->groupBy('user_id')
						->orderBy('message_centers.id','DESC')->get();
		$params['message_center'] = $messageCenter;
        return view('operator.message_center_list',$params);
    }
	
	public function reportFilter(){
		$agent = array();
		$agentDatas = Agent::select('id','first_name','last_name','username')->where('status','1')->get();
		foreach($agentDatas as $agentData){
			$agent[$agentData->id] = $agentData->username.' ('.$agentData->first_name.' '.$agentData->last_name.')';
		}
    	return view('operator.report')->with('agent',$agent);
    }
	
	public function reportFilterPost(Request $request){
		$inputData = $request->all();
		$mission = Mission::where('agent_id','!=','0')->where(function ($query) {
								$query->where('payment_status',1)
									  ->orWhere('payment_status',2);
							});
		if($request->agent_name){
			$mission = $mission->whereIn('agent_id',array_filter($request->agent_name));
		}
		if($request->from_date && $request->to_date){
			$mission = $mission->whereBetween('created_at',[Carbon::parse($request->from_date)->format('yy-m-d'), Carbon::parse($request->to_date)->format('yy-m-d')]);
		}
		$result = $mission->get();
		
		if($request->formet == 1){
			$customPaper = array(0,0,500.00,850.80);
			$pdf = \PDF::loadView('pdf.all_agent_report', ['results'=>$result])->setPaper($customPaper, 'landscape');
			return $pdf->download('report.pdf');
		}else{
			
			$excelResult = array();
			$original_amount= 0;
			$total_hours_sum = 0;
			foreach($result as $results){
				
					if($results->intervention == 'Guard_service'){
						$intervention = trans('dashboard.agents.Guard_service');
					}else if($results->intervention == 'Intervention'){
						$intervention = trans('dashboard.agents.Intervention');
					}else{
						$intervention = trans('dashboard.agents.Security_patrol');
					}
				$excelResult[] = array(
										trans('dashboard.mission.start_time')=>Carbon::parse($results->created_at)->format('d-m-yy'),
										trans('dashboard.mission.mission_id') => Helper::mission_id_str($results->id),
										trans('dashboard.mission.title') => $results->title,
										trans('dashboard.agent') => ucfirst($results->agent_details->first_name.' '.$results->agent_details->last_name),
										trans('dashboard.customer_name') => ucfirst($results->customer_details->first_name.' '.$results->customer_details->last_name),
										trans('dashboard.agents.time_intervel') => $results->total_hours.' '.trans('dashboard.hours'),
										trans('dashboard.amount') => $results->amount.' €',
										trans('dashboard.agents.intervention') => $intervention,
										trans('dashboard.mission.location') => $results->location,
										trans('dashboard.mission.started_at') => ($results->started_at) ? Carbon::parse($results->started_at)->format('d-m-yy H:i:s') : '',
										trans('dashboard.mission.ended_at') => ($results->ended_at) ? Carbon::parse($results->ended_at)->format('d-m-yy H:i:s') : ''
									);
					$original_amount+= $results->amount;
					$total_hours_sum+= $results->total_hours;
			}
			if(!empty($excelResult)){
				$excelResult[count($excelResult)] = array(
										trans('dashboard.mission.start_time')=>'',
										trans('dashboard.mission.mission_id')=>'',
										trans('dashboard.mission.title')=>'',
										trans('dashboard.agent')=>'',
										trans('dashboard.customer_name')=>trans('dashboard.agents.total_hours_worked'),
										trans('dashboard.agents.time_intervel')=>$total_hours_sum.' '.trans('dashboard.hours'),
										trans('dashboard.amount')=>trans('dashboard.grand_total').' '.$original_amount.' €'
										);
			}
			
			return \Excel::create('report', function($excel) use($excelResult) {
				$excel->sheet('Sheetname', function($sheet) use($excelResult) {
					$sheet->fromArray($excelResult);
				});
			})->export('xls');
		}
	}
	
	public function messageCenterId($id){
		$id = Helper::decrypt($id);
		$customer = Customer::where('user_id',$id)->first();
		if($customer){
			$user_messages = MessageCenter::select('customers.user_id','customers.first_name','customers.last_name','message_centers.message','message_centers.message_type')->join('customers','customers.user_id','message_centers.user_id')->where('message_centers.user_id',$id)->orderBy('message_centers.created_at','ASC')->get();
		}else{
			$user_messages = MessageCenter::select('agents.user_id','agents.first_name','agents.last_name','message_centers.message','message_centers.message_type')->join('agents','agents.user_id','message_centers.user_id')->where('message_centers.user_id',$id)->orderBy('message_centers.created_at','ASC')->get();
		}
		$message = MessageCenter::where('user_id',$id)->where('message_type','!=','send_by_op')->update(array('status'=>'2'));
		$opData = Operator::select('first_name','last_name')->where('user_id',Auth::id())->first();
		$params = array();
		$params['user_id'] =Auth::id();
		$params['cus_id'] = (isset($user_messages[0])) ? $user_messages[0]->user_id : $id;
		$params['profile'] = '';
		$params['user_messages'] = $user_messages;
		$params['opData'] = $opData;
        return view('operator.message_center',$params);
    }
	
	public function messageCenterPost(Request $request){
		$inputData = $request->all();
		$updateData = array('user_id'=>$request->cus_id,'operator_id'=>$request->user_id,
							'message'=>$request->send_message,'message_type'=>'send_by_op','status'=>'1',
							'created_at'=>Carbon::now(),'updated_at'=>Carbon::now()
						);
		MessageCenter::insert($updateData);
		$opData = Operator::where('user_id',$request->user_id)->first();
		$name_op = ($opData) ? $opData->first_name.' '.$opData->last_name : 'Unknown';
		return response()->json(array('status'=>1,'message_type'=>'send_by_op','message'=>$name_op));
	}

    /**
     * @return mixed
     * @method processRefundRequest
     * @purpose Process Refund Requests
     */
    public function processRefundRequest(Request $request){
        try{
            $record_id = Helper::decrypt($request->record_id);
            $refund_status = $request->refund_status;
            if($refund_status==1){
                $record = RefundRequest::where('id',$record_id)->first();
                $mission_id = $record->mission_id;
                $this->print($mission_id);
            }
            if($refund_status==2){
                $result = RefundRequest::where('id',$record_id)->update(['status'=>3]);
                if($result){
                    $response['message'] = trans('messages.refund_rejected');
                    $response['delayTime'] = 2000;
                    $response['url'] = url('operator/refund-requests');
                    return $this->getSuccessResponse($response);
                }        
            }
        }catch(\Exception $e){
            return response($this->getErrorResponse($e->getMessage()));
        }
    }

    /**
     * @param $mission_id
     * @return mixed
     * @method viewMissionDetailsRefund
     * @purpose View refund mission details
     */
    public function viewMissionDetailsRefund($mission_id){
        $mission_id = Helper::decrypt($mission_id);
        $data['mission'] = Mission::where('id',$mission_id)->first();
        return view('operator.view_mission_details_refund',$data);
    }


    /**
     * @param $mission_id
     * @return mixed
     * @method refundMissionAmount
     * @purpose Refund mission amount
     */
    public function refundMissionAmount(Request $request){
        try {
            $mission_id = Helper::decrypt($request->mission_id);
            $charge_id = $request->charge_id;
            $response = $this->refundCharge($charge_id);
            if($response['status']=='succeeded'){
                RefundRequest::where('mission_id',$mission_id)->update(['status'=>1]);
                $result = UserPaymentHistory::where('charge_id',$charge_id)->where('mission_id',$mission_id)->update(['refund_status'=>$response['status']]);
                if($result){
                    $response['message'] = trans('messages.amount_refunded');
                    $response['delayTime'] = 2000;
                    $response['url'] = url('operator/refund-mission-view/'.Helper::encrypt($mission_id));
                    return $this->getSuccessResponse($response);
                }
            }
        } catch (\Exception $e) {
			if($e->getMessage() == "No such charge: 'bank transfer'"){
				return $this->getErrorResponse(trans("messages.no_such_charge"));
			}
			if(strpos($e->getMessage(), 'a similar object exists in test mode, but a live mode key was used to make this request')){
				return $this->getErrorResponse(trans("messages.a_similar_object_exists",['name' => explode("'", $e->getMessage())[1]]));
			}
            return response($this->getErrorResponse($e->getMessage()));
        }
    } 

    /**
     * @param $request
     * @return mixed
     * @method blockUnblockAgent
     * @purpose Block or Unblock an agent
     */
    public function blockUnblockAgent(Request $request){
        try{
            $agent_id = Helper::decrypt($request->agent_id);
            $type = $request->type;
            if($type==1){
                $result = Agent::where('id',$agent_id)->update(['status'=>3]);
            }
            if($type==0){
                $result = Agent::where('id',$agent_id)->update(['status'=>1]);
            }
            if($result){
                $response['message'] = trans('messages.agent_status_changed');
                $response['delayTime'] = 2000;
                $response['url'] = url('operator/agents');
                return $this->getSuccessResponse($response);
            }else{
                return response(trans('messages.error'));   
            }
        }catch(\Exception $e){
            return response($this->getErrorResponse($e->getMessage()));  
        }

    }  
    
    public function missionChageStatus($status, $id){
        $mission_id = Helper::decrypt($id);
        if($status == 'archive'){
            $status = 10;
        }
//        dd();
        $mission = Mission::where('id',$mission_id)->update(['status'=>$status]);
//        dd($mission);
        return redirect()->back();        
//        $response['url'] = url()->previous();
//        return $this->getSuccessResponse($response);
    }

    public function agent_information_edit(Request $request){ 
        
        if(isset($_REQUEST) && !empty($_REQUEST)){
            $validatedData = $request->validate([
                'heading' => 'required',
                'desc' => 'required',
                'desc1' => 'required',
                'desc2'=>'required',
                'type'=>'required'
            ]);
            $array = [
                'heading'=>($_REQUEST['heading'])?$_REQUEST['heading']:'',
                'desc'=>($_REQUEST['desc'])?$_REQUEST['desc']:'',
                'desc1'=>($_REQUEST['desc1'])?$_REQUEST['desc1']:'',
                'desc2'=>($_REQUEST['desc2'])?$_REQUEST['desc2']:'',
                'type'=>($_REQUEST['type'])?$_REQUEST['type']:'',
            ];
            $mission = Mission::save_agent_info($array);
            Session::flash ( 'Success', "Success" );
            return Redirect::back ();
        }
        $res = Mission::get_agent_info(1)->first();
        $data['res_data'] = $res;
        return view('operator.agent_information_edit',$data);

    }

    public function agent_information(){
        $res = Mission::get_agent_info(3);
        $data['res_data'] = $res;
        return view('agent_information',$data);
    }
	
	public function privacyPolicy(){
        return view('privacyPolicy');
    }
	
	public function legalNotice(){
        return view('legal-notice');
    }
	
	public function generalTermsAndConditions(){
        return view('generalTermsAndConditions');
    }
	
	public function generalTermsOfUse(){
        return view('general_terms_of_use');
    }

    public function agent_information_edit_fr(Request $request){ 
        
        if(isset($_REQUEST) && !empty($_REQUEST)){
            $validatedData = $request->validate([
                'heading' => 'required',
                'desc' => 'required',
                'desc1' => 'required',
                'desc2'=>'required',
                'type'=>'required'
            ]);
            $array = [
                'heading'=>($_REQUEST['heading'])?$_REQUEST['heading']:'',
                'desc'=>($_REQUEST['desc'])?$_REQUEST['desc']:'',
                'desc1'=>($_REQUEST['desc1'])?$_REQUEST['desc1']:'',
                'desc2'=>($_REQUEST['desc2'])?$_REQUEST['desc2']:'',
                'type'=>($_REQUEST['type'])?$_REQUEST['type']:'',
            ];
            $mission = Mission::save_agent_info($array);
            Session::flash ( 'Success', "Success" );
            return Redirect::back ();
        }
        $res = Mission::get_agent_info(2)->first();
        $data['res_data'] = $res;
        return view('operator.agent_information_edit_fr',$data);

    }

}
  