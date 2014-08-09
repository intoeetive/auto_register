<?php

/*
=====================================================
 Auto Register
-----------------------------------------------------
 http://www.intoeetive.com/
-----------------------------------------------------
 Copyright (c) 2014 Yuri Salimovskiy
=====================================================
 This software is intended for usage with
 ExpressionEngine CMS, version 2.0 or higher
=====================================================
 File: ext.auto_register.php
-----------------------------------------------------
 Purpose: Create user account when attempting to log in with not registered email
=====================================================
*/

if ( ! defined('BASEPATH'))
{
	exit('Invalid file request');
}

class Auto_register_ext {

	var $name	     	= 'Auto Register';
	var $version 		= '1.0';
	var $description	= 'Create user account when attempting to log in with not registered email';
	var $settings_exist	= 'y';
	var $docs_url		= 'https://github.com/intoeetive/auto_register/README';
    
    var $settings 		= array();
    
    var $email_subject = 'User account has been created for you';
    var $email_template = 'Hello,
    
we have automatically created user account for you.

To begin using your new account, please visit the following URL:

{unwrap}{activation_url}{/unwrap}

Your password is: {password}  

{site_name}

{site_url}';

    
	/**
	 * Constructor
	 *
	 * @param 	mixed	Settings array or empty string if none exist.
	 */
	function __construct($settings = '')
	{
		$this->EE =& get_instance();
		
		$this->settings = $settings;
        
        $this->EE->lang->loadfile('member');
	}
    
    /**
     * Activate Extension
     */
    function activate_extension()
    {
        
        $hooks = array(
			array(
    			'hook'		=> 'member_member_login_start',
    			'method'	=> 'member_member_login_start',
    			'priority'	=> 10
    		)
    	);
    	
        foreach ($hooks AS $hook)
    	{
    		$data = array(
        		'class'		=> __CLASS__,
        		'method'	=> $hook['method'],
        		'hook'		=> $hook['hook'],
        		'settings'	=> '',
        		'priority'	=> $hook['priority'],
        		'version'	=> $this->version,
        		'enabled'	=> 'y'
        	);
            $this->EE->db->insert('extensions', $data);
    	}	
        
    }
    
    /**
     * Update Extension
     */
    function update_extension($current = '')
    {
    	if ($current == '' OR $current == $this->version)
    	{
    		return FALSE;
    	}
        
    	
    	$this->EE->db->where('class', __CLASS__);
    	$this->EE->db->update(
    				'extensions', 
    				array('version' => $this->version)
    	);
    }
    
    
    /**
     * Disable Extension
     */
    function disable_extension()
    {
    	$this->EE->db->where('class', __CLASS__);
    	$this->EE->db->delete('extensions');
    }
    
    
    
    function settings()
    {
        $settings = array();
        
        $sites = array();
        $sites_q = $this->EE->db->select('site_id, site_name')->from('sites')->get();
        foreach ($sites_q->result_array() as $row)
        {
            $sites[$row['site_id']] = $row['site_name'];
        }
        
        $settings['sites_enabled']    = array('ms', $sites, array());
        
        $settings['email_subject']    = array('i', '', $this->email_subject);
        $settings['email_template']    = array('t', array('rows' => '20'), $this->email_template);
        
        return $settings;
    }
    
    
    function member_member_login_start()
    {
        //no email submitted? do nothing  
        if ($this->EE->input->post('email')=='')
        {
            return;
        }
        
        //is the extension enabled for this site?
        if (!in_array($this->EE->config->item('site_id'), $this->settings['sites_enabled']))
        {
            return;
        }
        
        //if email is already in database, do nothing
        $get_email_q = $this->EE->db->select('email')
                        ->from('members')
                        ->where('email', $this->EE->input->post('email'))
                        ->or_where('username', $this->EE->input->post('email'))
                        ->get();
        if ($get_email_q->num_rows()>0)
        {
            return;
        }
        
        //otherwise, let's create an account
        $data = array();
        $data['email'] = $data['username'] = $data['screen_name'] = $this->EE->input->post('email');
        $data['group_id'] = ($this->EE->config->item('req_mbr_activation')=='none') ? $this->EE->config->item('default_member_group') : 4;
		$data['ip_address']  = $this->EE->input->ip_address();
		$data['unique_id']	= $this->EE->functions->random('encrypt');
		$data['join_date']	= $this->EE->localize->now;
		$data['language']	= ($this->EE->config->item('deft_lang')) ? $this->EE->config->item('deft_lang') : 'english';
		$data['time_format'] = ($this->EE->config->item('time_format')) ? $this->EE->config->item('time_format') : 'us';
		$data['timezone']	= ($this->EE->config->item('default_site_timezone') && $this->EE->config->item('default_site_timezone') != '') ? $this->EE->config->item('default_site_timezone') : $this->EE->config->item('server_timezone');

		$this->EE->db->query($this->EE->db->insert_string('exp_members', $data));
		$member_id = $this->EE->db->insert_id();
        
        //set the password
        $password = ($this->EE->input->post('password')!='')?$this->EE->input->post('password'):$this->EE->functions->random('alnum', 8);
        $this->EE->load->library('auth');
        $this->EE->auth->update_password($member_id, $password);

		$this->EE->db->query($this->EE->db->insert_string('exp_member_data', array('member_id' => $member_id)));

		$this->EE->db->query($this->EE->db->insert_string('exp_member_homepage', array('member_id' => $member_id)));

 		//send the email
        $this->EE->config->item('req_mbr_activation');
		if ($this->EE->config->item('req_mbr_activation') == 'email')
		{
			$action_id  = $this->EE->functions->fetch_action_id('Member', 'activate_member');

			$name = ($data['screen_name'] != '') ? $data['screen_name'] : $data['username'];

			$board_id = ($this->EE->input->get_post('board_id') !== FALSE && is_numeric($this->EE->input->get_post('board_id'))) ? $this->EE->input->get_post('board_id') : 1;

			$forum_id = ($this->EE->input->get_post('FROM') == 'forum') ? '&r=f&board_id='.$board_id : '';

			$add = ($mailinglist_subscribe !== TRUE) ? '' : '&mailinglist='.$_POST['mailinglist_subscribe'];
            
            $authcode_data = array('authcode' => $this->EE->functions->random('alnum', 10));

			$swap = array(
				'name'				=> $name,
				'activation_url'	=> $this->EE->functions->fetch_site_index(0, 0).QUERY_MARKER.'ACT='.$action_id.'&id='.$authcode_data['authcode'].$forum_id.$add,
				'site_name'			=> stripslashes($this->EE->config->item('site_name')),
				'site_url'			=> $this->EE->config->item('site_url'),
				'username'			=> $data['username'],
				'email'				=> $data['email'],
                'password'			=> $password
			 );

			$email_subject = ($this->settings['email_subject']!='')?$this->settings['email_subject']:$this->email_subject;
            $email_template = ($this->settings['email_template']!='')?$this->settings['email_template']:$this->email_template;
            $template_q = $this->EE->functions->fetch_email_template('mbr_activation_instructions');
			$email_tit = $this->_var_swap($email_subject, $swap);
			$email_msg = $this->_var_swap($email_template, $swap);

			// Send email
			$this->EE->load->helper('text');

			$this->EE->load->library('email');
			$this->EE->email->wordwrap = true;
			$this->EE->email->from($this->EE->config->item('webmaster_email'), $this->EE->config->item('webmaster_name'));
			$this->EE->email->to($data['email']);
			$this->EE->email->subject($email_tit);
			$this->EE->email->message(entities_to_ascii($email_msg));
			$this->EE->email->Send();
            
            $this->EE->db->where('member_id', $member_id);
	        $this->EE->db->update('members', $authcode_data);

		}
        
        $this->EE->stats->update_member_stats();
        
        $msg_data = array(	'title' 	=> $this->EE->lang->line('mbr_registration_complete'),
            				'heading'	=> $this->EE->lang->line('thank_you'),
            				'content'	=> lang('mbr_registration_completed')."\n\n".lang('mbr_membership_instructions_email'),
            				//'redirect'	=> $_POST['RET'],
            				'link'		=> array($_POST['RET'], $this->EE->config->item('site_name')),
                            //'rate'		=> 5
        			 );
			
		$this->EE->output->show_message($msg_data);
    }
    

	/**
	 * Replace variables
	 */
	function _var_swap($str, $data)
	{
		if ( ! is_array($data))
		{
			return FALSE;
		}

		foreach ($data as $key => $val)
		{
			$str = str_replace('{'.$key.'}', $val, $str);
		}

		return $str;
	}
  

}
// END CLASS
