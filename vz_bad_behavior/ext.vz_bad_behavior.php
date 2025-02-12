<?php  if ( ! defined('BASEPATH')) exit('No direct script access allowed');
define('BB2_CWD', dirname(__FILE__));

/**
 * VZ Bad Behavior Extension
 *
 * @package		ExpressionEngine
 * @subpackage	Addons
 * @category	Extension
 * @author		Eli Van Zoeren
 * @link		http://elivz.com
 */

class Vz_bad_behavior_ext {
	
	public $settings 		= array();
	public $description		= 'EE implementation of the spam-blocking Bad Behavior script.';
	public $docs_url		= 'http://elivz.com/blog/single/bad_behavior/';
	public $name			= 'VZ Bad Behavior';
	public $settings_exist	= 'y';
	public $version			= '1.0.3';
	
	private $EE;
	
	/**
	 * Constructor
	 */
	public function __construct($settings = '')
	{
		$this->EE =& get_instance();
		$this->settings = $settings;
	}
	
	private $default_settings = array(
        'verbose' => 'n',
        'logging' => 'y',
        'display_stats' => 'y',
        'strict' => 'n',
        'httpbl_key' => '',
        'httpbl_threat' => '25',
        'httpbl_maxage' => '30',
        'offsite_forms' => 'n'
    );
	
	// ----------------------------------------------------------------------
	
	/**
	 * Activate Extension
	 */
	public function activate_extension()
	{
		// Setup custom settings in this array.
		$this->settings = array();
		
		$data = array(
			'class'		=> __CLASS__,
			'method'	=> 'bad_behavior',
			'hook'		=> 'sessions_start',
			'settings'	=> serialize($this->settings),
			'version'	=> $this->version,
			'enabled'	=> 'y'
		);
    	
    	// Create the table for BB to store its data
        $this->EE->db->query(
            "CREATE TABLE IF NOT EXISTS `".$this->EE->db->dbprefix.'bad_behavior'."` (
            `id` INT(11) NOT NULL auto_increment,
            `ip` TEXT NOT NULL,
            `date` DATETIME NOT NULL default '0000-00-00 00:00:00',
            `request_method` TEXT NOT NULL,
            `request_uri` TEXT NOT NULL,
            `server_protocol` TEXT NOT NULL,
            `http_headers` TEXT NOT NULL,
            `user_agent` TEXT NOT NULL,
            `request_entity` TEXT NOT NULL,
            `key` TEXT NOT NULL,
            INDEX (`ip`(15)),
            INDEX (`user_agent`(10)),
            PRIMARY KEY (`id`) );"
        );
        
        // Enable the extension
		$this->EE->db->insert('extensions', $data);
        
        // Use default settings
        $this->default_settings['log_table'] = $this->EE->db->dbprefix.'bad_behavior';
    	$this->EE->db->update('extensions', array('settings' => serialize($this->default_settings)), array('class' => __CLASS__));
	}

	/**
	 * Disable Extension
	 */
	public function disable_extension()
	{
		// Delete the bad_behavior table
		$this->EE->db->query("DROP TABLE IF EXISTS ".$this->EE->db->dbprefix.'bad_behavior');
		
		// Remove the extension settings
		$this->EE->db->where('class', __CLASS__);
		$this->EE->db->delete('extensions');
	}	
	
	// ----------------------------------------------------------------------
	
	/**
	 * Display Settings Form
	 */
    function settings_form($settings)
    {
        global $bb_default_settings;
        $this->EE->load->helper('form');
        $this->EE->load->library('table');
        
        // Get the recently blocked list
        $blocked = $this->EE->db->query("SELECT * FROM " . $settings['log_table'] . " WHERE `key` NOT LIKE '00000000'")->result_array();
		
        $data = array(
            'settings'  => $settings,
            'blocked'   => array_reverse($blocked)
        );
		
        return $this->EE->load->view('index', $data, true);
	}
	
	/**
     * Save Settings
     */
    function save_settings()
    {
    	if (empty($_POST))
    	{
    		show_error($this->EE->lang->line('unauthorized_access'));
    	}
    	
    	unset($_POST['submit']);
    	
    	$this->EE->db->where('class', __CLASS__);
    	$this->EE->db->update('extensions', array('settings' => serialize($_POST)));
    	
    	$this->EE->session->set_flashdata(
    		'message_success',
    	 	$this->EE->lang->line('preferences_updated')
    	);
    }
	
	// ----------------------------------------------------------------------
	
	/**
	 * bad_behavior
	 */
	public function bad_behavior()
	{
        // Calls inward to Bad Behavor itself.
        require_once(BB2_CWD . "/bad-behavior/version.inc.php");
        require_once(BB2_CWD . "/bad-behavior/core.inc.php");
        
        bb2_start(bb2_read_settings());
	}

	// ----------------------------------------------------------------------
}


// ----------------------------------------------------------------------

// Bad Behavior callback functions.

// Return current time in the format preferred by your database.
function bb2_db_date()
{
	$EE =& get_instance();
	return gmdate('Y-m-d H:i:s', $EE->localize->now);
}

// Return affected rows from most recent query.
function bb2_db_affected_rows()
{
	$EE =& get_instance();
	return $EE->db->affected_rows();
}

// Escape a string for database usage
function bb2_db_escape($string)
{
	$EE =& get_instance();
	return $EE->db->escape_str($string);
}

// Return the number of rows in a particular query.
function bb2_db_num_rows($result)
{
    return $result !== FALSE ? $result->num_rows() : 0;
}

// Run a query and return the results, if any.
// Should return FALSE if an error occurred.
// Bad Behavior will use the return value here in other callbacks.
function bb2_db_query($query)
{
	$EE =& get_instance();
	return $EE->db->query($query);
}

// Return all rows in a particular query.
// Should contain an array of all rows generated by calling mysql_fetch_assoc()
// or equivalent and appending the result of each call to an array.
function bb2_db_rows($result)
{
	if ($result->num_rows() > 0)
    {
        return $results->result_array() ;
    }
}

// Return emergency contact email address.
function bb2_email()
{
	$EE =& get_instance();
	return $EE->config->item('webmaster_email');
}

// retrieve settings from database
function bb2_read_settings()
{
    global $bb_default_settings;
	$EE =& get_instance();
	$saved_settings = array();
	
	// Ugh, we have to go through this whole rigamarole to get the settings,
	// since we're not inside the extension's object.
    if (isset($EE->extensions->extensions['sessions_start']))
    {
        foreach($EE->extensions->extensions['sessions_start'] as $priority => $extension)
        {
            if (isset($extension['Vz_bad_behavior_ext']))
            {
                // Retrieve the saved settings
                if ($extension['Vz_bad_behavior_ext']['1'] != '')
                {
                    $settings = unserialize($extension['Vz_bad_behavior_ext']['1']);
                    
                    // Convert strings to booleans
                    foreach ($settings as $key => $value)
                    {
                        if ($value === 'y')
                        {
                            $settings[$key] = true;
                        }
                        elseif ($value == 'n')
                        {
                            $settings[$key] = false;
                        }
                    }
                    
                    return $settings;
                }
			}
		}
	}
	
	// Couldn't get the settings, oh well
	return false;
}

// write settings to database
function bb2_write_settings($settings)
{
    // Not needed, since we have a control panel for changing settings
	return false;
}

// installation
function bb2_install()
{
	// We are creating the table in the extension's "enable" function
	return false;
}

// Screener
// Insert this into the <head> section of your HTML through a template call
// or whatever is appropriate. This is optional we'll fall back to cookies
// if you don't use it.
function bb2_insert_head()
{
	global $bb2_javascript;
	echo $bb2_javascript;
}

// Display stats? This is optional.
function bb2_insert_stats($force = true)
{
	return false;
}

// Return the top-level relative path of wherever we are (for cookies)
// You should provide in $url the top-level URL for your site.
function bb2_relative_path()
{
	$EE =& get_instance();
	return str_replace('//', '/', $EE->config->item('cookie_path').'/');
}

/* End of file ext.vz_bad_behavior.php */
/* Location: /system/expressionengine/third_party/vz_bad_behavior/ext.vz_bad_behavior.php */