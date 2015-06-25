<?php

class BPBuddySlackComponent extends BP_Component {
 
	/**
	 * Initializes the plugin by setting localization, filters, and functions that need to hook into WordPress and BuddyPress.
	 */
	public function __construct() {

		parent::start(
			// Unique component ID
			'buddyslack',

			// Used by BP when listing components (eg in the Dashboard)
			__( 'BuddySlack', 'buddyslack' )
		);

		// Catch <form> submits
		
		add_action( 'bp_actions', array( $this, 'catch_form_submit' ) );

		// BuddyPress activities

		add_action( 'bp_activity_after_save', array( $this, 'send_activity_to_slack' ), 10, 1 );

		// Admin Menu

	    add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_init', array( $this, 'admin_page_init' ) );

		if ( ! defined('BUDDYSLACK_ADMIN_PERMISSIONS') ) define("BUDDYSLACK_ADMIN_PERMISSIONS", "manage_options");

	}

	/**
	 * Set up component data, as required by BP.
	 */
	public function setup_globals( $args = array() ) {
		parent::setup_globals( array(
			'slug'          => 'slack',
			'has_directory' => false,
		) );
	}

	/**
	 * Set up component navigation, and register display callbacks.
	 */
	public function setup_nav( $main_nav = array(), $sub_nav = array() ) {

		// Determine user to use
		if ( bp_displayed_user_domain() ) {
			$user_domain = bp_displayed_user_domain();
		} elseif ( bp_loggedin_user_domain() ) {
			$user_domain = bp_loggedin_user_domain();
		} else {
			return;
		}

		$this->options = get_option( 'buddyslack_options' );

		// Only allow the user's settings page to be accessible if the admin has the option checked
		// 
		if ( isset( $this->options['bp_user_settings_display'] ) && $this->options['bp_user_settings_display'] == "on" ) {

			$settings_link = trailingslashit( $user_domain . 'settings' );

			$main_nav[] = array(
				'name'            => __( 'Slack', 'buddypress' ),
				'slug'            => 'slack',
				'parent_url'      => $settings_link,
				'parent_slug'     => 'settings',
				'screen_function' => array( $this, 'settings_screen' ),
				'position'        => 90,
				'user_has_access' => true
			);

			$sub_nav[] = array(
				'name'            => __( 'Slack', 'buddypress' ),
				'slug'            => 'slack',
				'parent_url'      => $settings_link,
				'parent_slug'     => 'settings',
				'screen_function' => array( $this, 'settings_screen' ),
				'position'        => 90,
				'user_has_access' => true
			);

			parent::setup_nav( $main_nav, $sub_nav );

		}

	}

	/**
	 * Sends The BuddyPress Activity Item To Slack
	 * $args is the value passed from the BP function bp_activity_add()
	 */
	public function send_activity_to_slack ( $args ) {

		// first, make sure we have the webhook URL. If we don't, cancel
		
		$buddyslack_options = get_option( 'buddyslack_options' );
		if ( !$buddyslack_options['webhook_url'] ) {
			return;
		}

		// is the disable setting enabled by the admin? if so, then don't proceed
		
		if ( isset($buddyslack_options['bp_disable']) && $buddyslack_options['bp_disable'] == "disable" ) {
			return;
		}

		// make sure there's an action to send

		if ( !$args->action ) {
			return;
		}

		// are we allowing activities of this BuddyPress component through?

		if ( $args->component == "groups" && ( !isset( $buddyslack_options['bp_allowed_activity']['component_group'] ) || $buddyslack_options['bp_allowed_activity']['component_group'] != 'component_group' ) ) {
			return;
		}
		if ( $args->component == "members" && ( !isset( $buddyslack_options['bp_allowed_activity']['component_members'] ) || $buddyslack_options['bp_allowed_activity']['component_members'] != 'component_members' ) ) {
			return;
		}
		if ( $args->component == "profile" && ( !isset( $buddyslack_options['bp_allowed_activity']['component_profile'] ) || $buddyslack_options['bp_allowed_activity']['component_profile'] != 'component_profile' ) ) {
			return;
		}
		if ( $args->component == "activity" && ( !isset( $buddyslack_options['bp_allowed_activity']['component_activity'] ) || $buddyslack_options['bp_allowed_activity']['component_activity'] != 'component_activity' ) ) {
			return;
		}

		// assign slack settings to variables

		$webhook_url = $buddyslack_options['webhook_url'];

		if ( $buddyslack_options['bot_name'] ) {
			$bot_name_override = $buddyslack_options['bot_name'];
		} else {
			$bot_name_override = false;
		}
		if ( $buddyslack_options['bot_icon_url'] ) {
			$bot_icon_override = $buddyslack_options['bot_icon_url'];
		} else {
			$bot_icon_override = false;
		}
		if ( $buddyslack_options['bot_icon_emoji'] ) {
			$bot_emoji_override = $buddyslack_options['bot_icon_emoji'];
		} else {
			$bot_emoji_override = false;
		}
		if ( $buddyslack_options['channel_override'] ) {
			$channel_override = $buddyslack_options['channel_override'];
		} else {
			$channel_override = false;
		}

		// next, make sure that the BuddyPress user is allowing his/her activity to be sent

		$buddyslack_activity_setting = get_user_meta ( bp_loggedin_user_id(), 'buddyslack_activity_setting', true );

		if ( !$buddyslack_activity_setting || $buddyslack_activity_setting != "push" ) {
			return;
		}

		// all clear - we should be allowed to make the attempt

		// let's assemble the JSON Data that we need to send over to slack

			// STEP ONE: Convert HTML links into the Slack link format

			$activity_string = preg_replace('/<a href=\"(.*?)\" title=\"(.*?)\">(.*?)<\/a>/', "<$1|$3>", $args->action);
			$activity_string = preg_replace('/<a href=\"(.*?)\">(.*?)<\/a>/', "<$1|$2>", $activity_string);
			$activity_string_content = preg_replace('/<a href=\"(.*?)\" title=\"(.*?)\">(.*?)<\/a>/', "<$1|$3>", $args->content);
			$activity_string_content = preg_replace('/<a href=\"(.*?)\">(.*?)<\/a>/', "<$1|$2>", $activity_string_content);
			$activity_permalink = bp_activity_get_permalink ( $args->id );

			if ( $activity_string_content ) {
				$activity_string .= '\n"'.$activity_string_content.'"';
			}
			if ( $activity_permalink ) {
				$activity_string .= '\n'.$activity_permalink;
			}

			// STEP TWO: Avengers, Assemble... sorry, I mean let's put together payload in JSON format so we can send to Slack

			$json_data_array = array (
				'text' => $activity_string
				);

			if ( $bot_name_override ) { $json_data_array['username'] = $bot_name_override; }
			if ( $bot_icon_override ) { $json_data_array['icon_url'] = $bot_icon_override; }
			if ( $bot_emoji_override ) { $json_data_array['icon_emoji'] = $bot_emoji_override; }
			if ( $channel_override ) { $json_data_array['channel'] = $channel_override; }

			$json_data = json_encode( $json_data_array );

			// print_r ($json_data); 

			// temp correction: fix line breaks

			$json_data = str_replace('\n','n',$json_data);

			// STEP THREE: Send!

			$response = wp_remote_post( $webhook_url, array(
				'method' => 'POST',
				'timeout' => 45,
				'redirection' => 5,
				'httpversion' => '1.0',
				'blocking' => true,
				'headers' => array(),
				'body' => $json_data,
				'cookies' => array()
			    )
			);

			// Uncomment the below if you are modifying this and want a simple way to see what Slack returns

			// if ( is_wp_error( $response ) ) {
			//    $error_message = $response->get_error_message();
			//    echo "Something went wrong: $error_message";
			// } else {
			//    echo 'Response:<pre>';
			//    print_r( $response );
			//    echo '</pre>';
			// }

	}


	public function settings_screen() {
		add_action( 'bp_template_content', array( $this, 'settings_screen_display' ) );
		bp_core_load_template( 'members/single/plugins' );
	}

	public function settings_screen_display() {

		$buddyslack_activity_setting = get_user_meta ( bp_loggedin_user_id(), 'buddyslack_activity_setting', true );

		?>

		<form id="settings-form-buddyslack" class="standard-form buddyslack-form" method="post" action="">
	
		<label for="checkbox-buddyslack-push">Activity</label>
		<input type="checkbox" value="push" id="checkbox-buddyslack-push" name="checkbox-buddyslack-push" <?php if ( $buddyslack_activity_setting && $buddyslack_activity_setting == "push" ) { ?>checked="checked"<?php } ?> /> Push My Activities To Slack
		<p><em>When checked, whenever you generate a public activity on this site... a notification is sent to that Slack channel.</em></p>
	
		<div class="submit">
			<input type="submit" class="auto" id="submit" value="Save Changes" name="submit">
		</div>

		<?php wp_nonce_field( '_buddyslack_save_settings', '_buddyslack_nonce' ); ?>	
	
		</form>

		<?
	}

	/**
	 * Catch form submit and process.
	 */
	public function catch_form_submit() {
		global $wpdb;

		$bp = buddypress();

		// Bail if this is not a submit of our form
		if ( ! isset( $_REQUEST['checkbox-buddyslack-push'] ) ) {
			return;
		}

		$redirect_url = bp_loggedin_user_domain() . '/settings/slack/';

		// Bail if the nonce check fails
		if ( ! isset( $_REQUEST['_buddyslack_nonce'] ) || ! wp_verify_nonce( $_REQUEST['_buddyslack_nonce'], '_buddyslack_save_settings' ) ) {
			bp_core_add_message( __( 'Please try again!', 'buddyslack' ), 'error' );
			bp_core_redirect( $redirect_url );
			die();
		}

		// Bail if the current user doesn't have the right to edit this
		if ( ! bp_is_my_profile() && ! current_user_can( 'bp_moderate' ) ) {
			bp_core_add_message( __( 'Please try again.', 'buddyslack' ), 'error' );
			bp_core_redirect( $redirect_url );
			die();
		}

		// The user has submitted a form to turn activity-to-slack on/off
		if ( isset( $_REQUEST['checkbox-buddyslack-push'] ) ) {

			$old_setting = get_user_meta( bp_loggedin_user_id(), 'buddyslack_activity_setting', true );

			if ( $_REQUEST['checkbox-buddyslack-push'] == "push" && $old_setting != "push" ) {
				update_user_meta( bp_loggedin_user_id(), 'buddyslack_activity_setting', 'push' );
				bp_core_add_message( __( 'Slack settings updated.', 'buddyslack' ), 'success' );
			} else {
				delete_user_meta( bp_loggedin_user_id(), 'buddyslack_activity_setting' );
				bp_core_add_message( __( 'Slack settings updated.', 'buddyslack' ), 'success' );
			}

		}

		bp_core_redirect( $redirect_url );
		die();

	}

	/**
	 * Create the setting menu in the WordPress admin
	 */
	public function admin_menu() {
	    	
	    add_options_page(
	        __("BuddySlack Settings"),
	        __("BuddySlack"),
	        BUDDYSLACK_ADMIN_PERMISSIONS,
	        "buddyslack",
	        array( $this, 'buddyslack_settings_page_main' )
	    );
    	
	}


	/**
	 * Display the page content for the custom BuddySlack settings in the admin
	 */
	public function buddyslack_settings_page_main() {

		//must check that the user has the required capability 
	    if (!current_user_can('manage_options'))
	    {
	      wp_die( __('You do not have sufficient permissions to access this page.') );
	    }

 		// Set class property
        $this->options = get_option( 'buddyslack_options' );

		echo '<div class="wrap">';

	    echo "<h2>" . __( 'BuddySlack Settings', 'buddyslack' ) . "</h2>";

		// settings form
		    
		    ?>

		<form name="admin-buddyslack-form" method="post" action="options.php">

            <?php
                settings_fields( 'buddyslack_slack_option_group' );  
                // settings_fields( 'buddyslack_bp_option_group' );   
                do_settings_sections( 'buddyslack-admin' );
                submit_button(); 
            ?>

        </form>

		<?php
		 
	}

    /**
     * Register and add admin settings
     */
    public function admin_page_init()
    {        
        register_setting(
            'buddyslack_slack_option_group', // Option group
            'buddyslack_options', // Option name
            array( $this, 'sanitize' ) // Sanitize
        );

        add_settings_section(
            'buddyslack_note', // ID
            'Please Note', // Title
            array( $this, 'print_note_info' ), // Callback
            'buddyslack-admin' // Page
        );  

        add_settings_section(
            'buddyslack_slack_settings', // ID
            'Slack Settings', // Title
            array( $this, 'print_slack_section_info' ), // Callback
            'buddyslack-admin' // Page
        );  

        add_settings_field(
            'webhook_url', // ID
            'Webhook URL', // Title 
            array( $this, 'option_webhook_callback' ), // Callback
            'buddyslack-admin', // Page
            'buddyslack_slack_settings' // Section           
        );      
   
        add_settings_field(
            'bot_name', // ID
            'Slack Bot Username', // Title 
            array( $this, 'option_bot_name_callback' ), // Callback
            'buddyslack-admin', // Page
            'buddyslack_slack_settings' // Section           
        ); 

        add_settings_field(
            'bot_icon_url', // ID
            'Slack Bot Icon', // Title 
            array( $this, 'option_bot_icon_callback' ), // Callback
            'buddyslack-admin', // Page
            'buddyslack_slack_settings' // Section           
        );         

        add_settings_field(
            'bot_icon_emoji', // ID
            'Slack Bot Emoji', // Title 
            array( $this, 'option_bot_emoji_callback' ), // Callback
            'buddyslack-admin', // Page
            'buddyslack_slack_settings' // Section           
        ); 

        add_settings_field(
            'channel_override', // ID
            'Channel Override', // Title 
            array( $this, 'option_channel_override_callback' ), // Callback
            'buddyslack-admin', // Page
            'buddyslack_slack_settings' // Section           
        ); 

        register_setting(
            'buddyslack_bp_option_group', // Option group
            'buddyslack_bp_options', // Option name
            array( $this, 'sanitize' ) // Sanitize
        );

        add_settings_section(
            'buddyslack_bp_settings', // ID
            'BuddyPress Settings', // Title
            array( $this, 'print_bp_section_info' ), // Callback
            'buddyslack-admin' // Page
        );  


        add_settings_field(
            'bp_disable', // ID
            'Disable', // Title 
            array( $this, 'option_bp_disable_callback' ), // Callback
            'buddyslack-admin', // Page
            'buddyslack_bp_settings' // Section           
        ); 

        add_settings_field(
            'bp_user_settings_display', // ID
            'User Settings', // Title 
            array( $this, 'option_bp_user_settings_display_callback' ), // Callback
            'buddyslack-admin', // Page
            'buddyslack_bp_settings' // Section           
        ); 

        add_settings_field(
            'bp_allowed_activities', // ID
            'Allowed Activities', // Title 
            array( $this, 'option_bp_allowed_activities_callback' ), // Callback
            'buddyslack-admin', // Page
            'buddyslack_bp_settings' // Section           
        ); 


    }


    /**
     * Sanitize each setting field as needed
     *
     * @param array $input Contains all settings fields as array keys
     */
    public function sanitize( $input )
    {

        $new_input = array();
        if( isset( $input['webhook_url'] ) )
            $new_input['webhook_url'] = sanitize_text_field( $input['webhook_url'] );
        if( isset( $input['bot_name'] ) )
            $new_input['bot_name'] = sanitize_text_field( $input['bot_name'] );
        if( isset( $input['bot_icon_url'] ) )
            $new_input['bot_icon_url'] = sanitize_text_field( $input['bot_icon_url'] );
        if( isset( $input['bot_icon_emoji'] ) )
            $new_input['bot_icon_emoji'] = sanitize_text_field( $input['bot_icon_emoji'] );
        if( isset( $input['channel_override'] ) )
            $new_input['channel_override'] = sanitize_text_field( $input['channel_override'] );

        if( isset( $input['bp_user_settings_display'] ) )
            $new_input['bp_user_settings_display'] = sanitize_text_field( $input['bp_user_settings_display'] );
        if( isset( $input['bp_disable'] ) )
            $new_input['bp_disable'] = sanitize_text_field( $input['bp_disable'] );
        if( isset( $input['bp_allowed_activity'] ) )
            $new_input['bp_allowed_activity'] = $input['bp_allowed_activity'];

        return $new_input;
    }


    /** 
     * Print the Note text
     */
    public function print_note_info()
    {
        print 'For this plugin to work you MUST login into your Slack settings and <a href="https://api.slack.com/incoming-webhooks">set up an incoming webhook integration in your Slack team</a>. Grab the provided webhook URL and use that in the settings below.<hr/>';
    }

    /** 
     * Print the Section text
     */
    public function print_slack_section_info()
    {
        print 'Settings specific to your Slack installation.';
    }

    /** 
     * Print the Section text
     */
    public function print_bp_section_info()
    {
        
    }

    /** 
     * Get the settings option array and display values
     */
    public function option_webhook_callback()
    {

        printf(
            '<input type="text" style="min-width: 400px;" id="webhook_url" name="buddyslack_options[webhook_url]" value="%s" />',
            isset( $this->options['webhook_url'] ) ? esc_attr( $this->options['webhook_url']) : ''
        );
        print('<div><em><small>This URL is found in your Setup Instructions for "Incoming WebHooks" in your Slack settings.<br/>This URL usually starts off with <strong>\'https://hooks.slack.com/services/\'</strong></small></em></div>');
    }

    /** 
     * Get the settings option array and display values
     */
    public function option_bot_name_callback()
    {

        printf(
            '<input type="text" id="bot_name" name="buddyslack_options[bot_name]" value="%s" />',
            isset( $this->options['bot_name'] ) ? esc_attr( $this->options['bot_name']) : ''
        );
        print('<div><em><small>You can customize the name of your Incoming Webhook. If you leave this blank, the displayed usernname will be "incoming-webhook".</small></em></div>');
    }

    /** 
     * Get the settings option array and display values
     */
    public function option_bot_icon_callback()
    {

        printf(
            '<input type="text" id="bot_icon_url" name="buddyslack_options[bot_icon_url]" value="%s" />',
            isset( $this->options['bot_icon_url'] ) ? esc_attr( $this->options['bot_icon_url']) : ''
        );
        print('<div><em><small>You can customize the icon of your Incoming Webhook by entering a URL of a 64x64 icon. If you leave this blank, Slack will use it\'s default icon for webhooks.</small></em></div>');
    }


    /** 
     * Get the settings option array and display values
     */
    public function option_bot_emoji_callback()
    {

        printf(
            '<input type="text" id="bot_icon_emoji" name="buddyslack_options[bot_icon_emoji]" value="%s" />',
            isset( $this->options['bot_icon_emoji'] ) ? esc_attr( $this->options['bot_icon_emoji']) : ''
        );
        print('<div><em><small>Instead of an icon, you can supply a Slack emjoi code. Example: <strong>:ghost:</strong><br/>This setting overrides the icon setting above.</small></em></div>');
    }


    /** 
     * Get the settings option array and display values
     */
    public function option_channel_override_callback()
    {

        printf(
            '<input type="text" id="channel_override" name="buddyslack_options[channel_override]" value="%s" />',
            isset( $this->options['channel_override'] ) ? esc_attr( $this->options['channel_override']) : ''
        );
        print('<div><em><small>A default channel should have already been assigned, but it can be overridden. A public channel can be specified with "#other-channel", and a Direct Message with "@username". Leave blank for default. </small></em></div>');
    }

    /** 
     * Get the settings option array and display values
     */
    public function option_bp_user_settings_display_callback()
    {

        echo '<input type="checkbox" id="bp_user_settings_display" value="on" name="buddyslack_options[bp_user_settings_display]" ';
        if ( isset( $this->options['bp_user_settings_display'] ) && $this->options['bp_user_settings_display'] == "on" ) {
        	echo 'checked="checked"';
        };
        echo ' />';
        print('<label for="bp_user_settings_display">Allow users a Slack settings menu in their BuddyPress user settings (giving them control if their activity gets posted to Slack).</label>');
    }

    /** 
     * Get the settings option array and display values
     */
    public function option_bp_disable_callback()
    {

        echo '<input type="checkbox" id="bp_disable" value="disable" name="buddyslack_options[bp_disable]" ';
        if ( isset( $this->options['bp_disable'] ) && $this->options['bp_disable'] == "disable" ) {
        	echo 'checked="checked"';
        };
        echo ' />';
        print('<label for="bp_disable">Check this to prevent any BuddyPress activities sent to Slack.</label>');
    }



    /** 
     * Get the settings option array and display values
     */
    public function option_bp_allowed_activities_callback()
    {



        echo '<div><input type="checkbox" id="bp_allowed_component_members" value="component_members" name="buddyslack_options[bp_allowed_activity][component_members]" ';
        if ( isset( $this->options['bp_allowed_activity']['component_members'] ) && $this->options['bp_allowed_activity']['component_members'] == "component_members" ) {
        	echo 'checked="checked"';
        };
        echo ' />';
        print('<label for="bp_allowed_component_members">Members Component</label></div>');


        echo '<div><input type="checkbox" id="bp_allowed_component_profile" value="component_profile" name="buddyslack_options[bp_allowed_activity][component_profile]" ';
        if ( isset( $this->options['bp_allowed_activity']['component_profile'] ) && $this->options['bp_allowed_activity']['component_profile'] == "component_profile" ) {
        	echo 'checked="checked"';
        };
        echo ' />';
        print('<label for="bp_allowed_component_profile">Profile Component</label></div>');


        echo '<div><input type="checkbox" id="bp_allowed_component_activity" value="component_activity" name="buddyslack_options[bp_allowed_activity][component_activity]" ';
        if ( isset( $this->options['bp_allowed_activity']['component_activity'] ) && $this->options['bp_allowed_activity']['component_activity'] == "component_activity" ) {
        	echo 'checked="checked"';
        };
        echo ' />';
        print('<label for="bp_allowed_component_activity">Activity Component</label></div>');

        echo '<div><input type="checkbox" id="bp_allowed_component_group" value="component_group" name="buddyslack_options[bp_allowed_activity][component_group]" ';
        if ( isset( $this->options['bp_allowed_activity']['component_group'] ) && $this->options['bp_allowed_activity']['component_group'] == "component_group" ) {
        	echo 'checked="checked"';
        };
        echo ' />';
        print('<label for="bp_allowed_component_group">Group Component</label></div>');

        print('<div><em><small>Planned future versions of this plugin will allow more detailed control of activities.</small></em></div>');

    }









}

