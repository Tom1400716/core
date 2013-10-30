<?php

defined('SYSPATH') or die('No direct script access.');

class Controller_Sso_Security extends Controller_Sso_Master {
    public function before() {
        parent::before();
        
        if($this->session()->get("sso_token_lock", false) && ($this->_action == "enable" || $this->_action == "disable")){
            $this->redirect("/sso/auth/checks");
            exit();
        }
    }
    
    /**
     * Authorise the user's access with their secondary password.
     */
    public function action_auth() {
        // Submitted the form?
        if (HTTP_Request::POST == $this->request->method()) {
            // Try and authenticate!
            $result = $this->_current_account->security->action_authorise($this->request->post("password"));
            if($result){
                $this->redirect("/sso/auth/checks");
                return;
            } else {
                $this->setMessage("Security Authorisation Error", "The secondary password you entered was incorrect.", "error");
            }
        }
        $this->setTitle("Secondary Password");
    }
    
    /**
     * Authorise the user's access with their secondary password.
     */
    public function action_replace() {
        // Get the security type we're working with here!
        $securityType = ($this->_current_account->security->loaded() ? $this->_current_account->security->type : Enum_Account_Security::MEMBER);
        
        // Submitted the form?
        if (HTTP_Request::POST == $this->request->method()) {
            // Try and authenticate the old password
            if(!$this->_current_account->security->loaded() || $this->_current_account->security->value == null){
                $result = true;
            } else {
                $result = $this->_current_account->security->action_authorise($this->request->post("old_password"));
            }
            
            // Continue?
            if($result){
                // Now we can set the new one!
                if($this->request->post("new_password") != $this->request->post("new_password2")){
                    $this->setMessage("Security Authorisation Error", "The two new passwords do not match, please try again.", "error");
                } elseif($this->_current_account->security->loaded() && $this->_current_account->security->hash($this->request->post("new_password")) == $this->_current_account->security->value){
                    $this->setMessage("Security Authorisation Error", "Your new password cannot be the same as your old password.", "error");
                } else {
                    // Update!
                    ORM::factory("Account_Security")->set_security($this->_current_account->id, $securityType, $this->request->post("new_password"));
                    ORM::factory("Account_Security")->action_authorise($this->request->post("new_password"), true);
                    
                    if($this->_current_token->loaded()){
                        // Send back and do some more checks!
                        $this->redirect("/sso/auth/checks");
                    } else {
                        $this->redirect("/sso/manage/display");
                    }
                    return;
                }
            } else {
                $this->setMessage("Security Authorisation Error", "The existing secondary password you entered was incorrect.", "error");
            }
        }
        
        // What are the requirements?
        $enum = "Enum_Account_Security_".ucfirst(strtolower(Enum_Account_Security::valueToType($securityType)));
        $requirements = array();

        if($enum::MIN_LENGTH > 0){
            $requirements[] = "A minimum length of ".$enum::MIN_LENGTH;
        }
        if($enum::MIN_ALPHA > 0){
            $requirements[] = "Contain a minimum of ".$enum::MIN_ALPHA." alphabetical (A-Z) characters.";
        }
        if($enum::MIN_NUMERIC > 0){
            $requirements[] = "Contain a minimum of ".$enum::MIN_NUMERIC." numeric (0-9) digits.";
        }
        if($enum::MIN_NON_ALPHANUM > 0){
            $requirements[] = "Contain a minimum of ".$enum::MIN_NON_ALPHANUM." none alpha-numeric characters, for example !)(><.,";
        }
        $this->_data["_requirements"] = $requirements;
        
        if(!$this->_current_account->security->loaded()){
            $this->setTitle("Activate Secondary Password");
            $this->_data["sls_type"] = "requested";
        } elseif($this->_current_account->security->value == null){
            $this->setTitle("Set Secondary Password");
            $this->_data["sls_type"] = "forced";
        } elseif(strtotime($this->_current_account->security->expires) < time() && $this->_current_account->security->expires != NULL) {
            $this->setTitle("Secondary Password Expired");
            $this->_data["sls_type"] = "expired";
        } else {
            $this->setTitle("Change Secondary Password");
            $this->_data["sls_type"] = "change";
        }
    }
    
    public function action_enable(){
        $this->_action = "replace";
        $this->action_replace();
    }
    
    public function action_disable(){
        // Let's check there's anything TO disable
        if(!$this->_current_account->security->loaded()){
            $this->redirect("/sso/manage/display");
            return;
        }
        
        // Are they allowed to "disable"?
        if($this->_current_account->security->type != Enum_Account_Security::MEMBER){
            $this->redirect("/sso/manage/display");
            return;
        }
        
        // Submitted the form?
        if (HTTP_Request::POST == $this->request->method()) {
            // Let's check the password matches!
            $result = $this->_current_account->security->action_authorise($this->request->post("password"));
            
            if($result){
                $this->_current_account->security->delete();
                $this->redirect("/sso/manage/display");
                return;
            } else {
                $this->setMessage("Invalid Password", "The password you entered is invalid.  Please try again.", "error");
            }
        }
    }
}