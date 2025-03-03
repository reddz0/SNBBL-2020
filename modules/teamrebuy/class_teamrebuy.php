<?php
/*
    This file is a template for modules.
    
    Note: the two terms functions and methods are used loosely in this documentation. They mean the same thing.
    
    How to USE a module once it's written:
    ---------------------------------
        Firstly you will need to register it in the modules/modsheader.php file. 
        The existing entries and comments should be enough to figure out how to do that.
        Now, let's say that your module (as an example) prints some kind of statistics containing box. 
        What should you then write on the respective page in order to print the box?
        
            if (Module::isRegistered('MyModule')) {
                Module::run('MyModule', array());
            }
        
        The second argument passed to Module::run() is the $argv array passed on to main() (see below).
*/

class TeamRebuy implements ModuleInterface
{

/***************
 * ModuleInterface requirements. These functions MUST be defined.
 ***************/

/*
 *  Basically you are free to design your main() function as you wish. 
 *  If you are writing a simple module that merely echoes out some data, you may want to have main() doing all the work (i.e. place all your code here).
 *  If you on the other hand are writing a module which is divided into several routines, you may (and should) use the main() as a wrapper for calling the appropriate code.
 *  
 *  The below main() example illustrates how main() COULD work as a wrapper, when the subdivision of code is done into functions in this SAME class.
 */
public static function main($argv) # argv = argument vector (array).
{
    #global $lng;
    #title($lng->getTrn('name', __CLASS__));
	title('Team Rebuy');
	$team = self::_teamSelect();
    if (is_numeric($team)) {
        self::_showteam($team);
	}
    return true;
}

protected static function _teamSelect() 
{
    global $lng;
    $_SUBMITTED = isset($_POST['team_as']) && $_POST['team_as'];
    $team = '';
    if ($_SUBMITTED) {
        $team = $_POST['team_as'];
    }
    ?>
    <br>
    <center>
    <form method='POST'>
    <input type="text" id='team_as' name="team_as" size="30" maxlength="50" value="<?php echo $team;?>">
    <script>
        $(document).ready(function(){
            var options, a;

            options = {
                minChars:3,
                    serviceUrl:'handler.php?type=autocomplete&obj=<?php echo T_OBJ_TEAM;?>',
            };
            a = $('#team_as').autocomplete(options);
        });
    </script>
    <br><br>
    <input type="submit" name="start_rebuy" value="START!">
    </form>
    </center>
    <br>
    <?php
    return $_SUBMITTED ? get_alt_col('teams', 'name', $team, 'team_id') : null;
}

protected static function _showteam($tid)
{
	$team_name = get_alt_col('teams', 'team_id', $tid, 'name');
	echo '<p>' . $team_name . ' Team Rebuy</p>';
}

/*
 *  This function returns information about the module and its author.
 */
public static function getModuleAttributes()
{
    return array(
        'author'     => 'Chris Reddy',
        'moduleName' => 'Team Rebuy',
        'date'       => '2025', # For example '2009'.
        'setCanvas'  => true, # If true, whenever your main() is run through Module::run() your code's output will be "sandwiched" into the standard HTML frame.
    );
}

/*
 *  This function returns the MySQL table definitions for the tables required by the module. If no tables are used array() should be returned.
 */
	public static function getModuleTables()
	{
		return array(
		);
	}

	public static function getModuleUpgradeSQL()
	{
		return array(
		);
	}

public static function triggerHandler($type, $argv){

    // Do stuff on trigger events.
    // $type may be any one of the T_TRIGGER_* types.
}

/***************
 * OPTIONAL subdivision of module code into class methods.
 * 
 * These work as in ordinary classes with the exception that you really should (but are strictly required to) only interact with the class through static methods.
 ***************/

private $attribute = 'Default value';

public function __construct($arg1)
{
    $this->attribute = $arg1;
}

public function myMethod()
{
    return $this->attribute;
}

public static function myStaticMethod($arg)
{
    $obj = new self('New value');
    echo $obj->myMethod();
}

}

