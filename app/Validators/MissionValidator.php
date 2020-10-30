<?php
namespace App\Validators;

use Illuminate\Http\Request;
Use Illuminate\Support\Facades\Validator;
use App\Validators\BaseValidator;
use Illuminate\Validation\Rule;

trait MissionValidator
{
    use BaseValidator;

    /**
     * @param   : Request $request
     * @return  : \Illuminate\Contracts\Routing\ResponseFactory|\Illuminate\Http\Response
     * @method  : createMissionValidations
     * @purpose : Validation rule for create new mission
     */
    public function createMissionValidations(Request $request){
        try{
            $validations = array(
            	'title'			=> 'required',
                'location' 		=> 'required',
                'start_date'	=> 'required',
                'end_date' 		=> 'required',
                'agent_type'    => 'required',
                'description' 	=> 'required',
            );
            $validator = Validator::make($request->all(),$validations);
            $this->response = $this->validateData($validator);
        }catch(\Exception $e){
            $this->response = $e->getMessage();
        }
        return $this->response;
    }


    /**
     * @param   : Request $request
     * @return  : \Illuminate\Contracts\Routing\ResponseFactory|\Illuminate\Http\Response
     * @method  : quickMissionValidations
     * @purpose : Validation rule for create quick mission
     */
    public function quickMissionValidations(Request $request){
        try{
			if($request->quick_book){
				$validations = [
					'title'         => 'required',
					'location'      => 'required',
					'total_hours'   => 'required',
					'agent_type'    => 'required|not_in:0',
					'start_date_time' => 'required_if:quick_book,0',
					'description'   => 'required',
				];
			}else{
				$validations = [
					'title'         => 'required',
					'location'      => 'required',
					'agent_type'    => 'required|not_in:0',
				];
			}
            $messages = [
                'start_date_time.required_if' => trans('validation.start_date_time')
            ];
            $validator = Validator::make($request->all(),$validations,$messages);
            $this->response = $this->validateData($validator);
        }catch(\Exception $e){
            $this->response = $e->getMessage();
        }
        return $this->response;
    }
	
	public function quickBankMissionValidations(Request $request){
        try{
            $validations = [
                'bank_transfer_payment_detail' => 'required',
            ];
			$messages = [
                'bank_transfer_payment_detail.required' => trans('validation.i_agree')
            ];
            $validator = Validator::make($request->all(),$validations,$messages);
            $this->response = $this->validateData($validator);
        }catch(\Exception $e){
            $this->response = $e->getMessage();
        }
        return $this->response;
    }
	
	public function feedbackValidations(Request $request){
        try{
            $validations = [
                'rating' => 'required',
                'message' => 'required',
            ];
            $validator = Validator::make($request->all(),$validations);
            $this->response = $this->validateData($validator);
        }catch(\Exception $e){
            $this->response = $e->getMessage();
        }
        return $this->response;
    }
	
	public function rejectMissionValidations(Request $request){
        try{
            $validations = [
                'reason' => 'required',
            ];
            $validator = Validator::make($request->all(),$validations);
            $this->response = $this->validateData($validator);
        }catch(\Exception $e){
            $this->response = $e->getMessage();
        }
        return $this->response;
    }
	
	public function customMissionRequest(Request $request){
        try{
            $validations = [
                'general_info' => 'required',
                'request_location' => 'required',
                'description' => 'required',
            ];
			// $messages = [
                // 'bank_transfer_payment_detail.required' => 'i agree is required while creating mission for future dates.'
            // ];
            $validator = Validator::make($request->all(),$validations);
            $this->response = $this->validateData($validator);
        }catch(\Exception $e){
            $this->response = $e->getMessage();
        }
        return $this->response;
    }
	
	public function quickUploadInvoiceMissionValidations(Request $request){
        try{
            $validations = [
                'upload_invoice' => 'required',
                'mission_id' => 'required',
            ];
			// $messages = [
                // 'bank_transfer_payment_detail.required' => 'i agree is required while creating mission for future dates.'
            // ];
            $validator = Validator::make($request->all(),$validations);
            $this->response = $this->validateData($validator);
        }catch(\Exception $e){
            $this->response = $e->getMessage();
        }
        return $this->response;
    }
	
}
