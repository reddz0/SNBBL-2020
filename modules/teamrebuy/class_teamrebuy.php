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
    global $lng, $coach;
	$IS_LOCAL_OR_GLOBAL_ADMIN = (isset($coach) && ($coach->ring == Coach::T_RING_GLOBAL_ADMIN || $coach->ring == Coach::T_RING_LOCAL_ADMIN));

	if (!$IS_LOCAL_OR_GLOBAL_ADMIN) {
		fatal("Sorry. Your access level does not allow you opening the requested page.");
	}
	
    #title($lng->getTrn('name', __CLASS__));
	$tid = array_shift($argv);
    if (!is_numeric($tid) || $tid == 0) {
		title('Team Rebuy');
		$tid = self::_teamSelect();
	}
	if (is_numeric($tid) && $tid > 0) {
		self::_showteam($tid);
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
    Select Team: <input type="text" id='team_as' name="team_as" size="30" maxlength="50" value="<?php echo $team;?>">
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
	global $coach;
	
	$t = new Team($tid);

	if (!$coach->isNodeCommish(T_NODE_LEAGUE, $t->f_lid)) {
		fatal("Sorry. Your access level does not allow you opening the requested page.");
	}

	title($t->name . ' Team Rebuy');

	setupGlobalVars(T_SETUP_GLOBAL_VARS__LOAD_LEAGUE_SETTINGS, array('lid' => $t->f_lid)); // Load correct $rules for league.

	/* Argument(s) passed to generating functions. */
	$ALLOW_EDIT = $t->allowEdit(); # Show team action boxes?
	$DETAILED   = true; #(isset($_GET['detailed']) && $_GET['detailed'] == 1);# Detailed roster view?

	/* Team pages consist of the output of these generating functions. */
	$m_error = '';
	list($matches, $pages) = Stats::getMatches(T_OBJ_TEAM, $t->team_id, false, false, false, false, array(), true, true);
	if (is_array($matches))
		$m_error = 'This team has ' . count($matches) . ' unplayed scheduled matches. Are you sure they are ready to ReBuy?';
	list($players, $players_backup, $jm_error) = self::_loadPlayers($DETAILED, $t); # Should come after handleActions().
	if ($jm_error !== '' || $m_error !== '') {
		echo '<p><strong>ERRORS FOUND</strong></p><ul>';
		if ($jm_error !== '')
			echo '<li>' . $jm_error . '</li>';
		if ($m_error !== '')
			echo '<li>' . $m_error . '</li>';
		echo '</ul><p><a href="'.urlcompile(T_URL_PROFILE,T_OBJ_TEAM,$t->team_id,false,false).'">Go to Team Page to resolve.</a></p>';
		return false;
	}
	self::_roster($ALLOW_EDIT, $DETAILED, $t, $players);
	return true;
}

	protected static function _loadPlayers($DETAILED, $t) {
		/*
			Lets prepare the players for the roster.
		*/
		global $settings;
		$error = '';
		$team = $t; // Copy. Used instead of $this for readability.
		$players = $players_org = array();
		$players_org = $team->getPlayers();
		// Make two copies: We will be overwriting $players later when the roster has been printed, so that the team actions boxes have the correct untempered player data to work with.
		foreach ($players_org as $p) {
			array_push($players, clone $p);
		}
		// Filter players depending on settings and view mode.
		$tmp_players = array();
		foreach ($players as $p) {
			if ($p->is_dead || $p->is_sold) {
				continue;
			}
			if ($p->is_journeyman) {
				$error = 'There is one or more journeymen on the roster that need to be removed first.';
				continue;
			}
			array_push($tmp_players, $p);
		}
		$players = $tmp_players;
		return array($players, $players_org, $error);
	}

	protected static function _roster($ALLOW_EDIT, $DETAILED, $t, $players) {
		global $rules, $settings, $lng, $skillididx, $coach, $DEA;
		$team = $t; // Copy. Used instead of $this for readability.

		/******************************
		 *   Make the players ready for roster printing.
		 ******************************/
		foreach ($players as $p) {
			/*
				Misc
			*/
			$p->name = preg_replace('/\s/', '&nbsp;', $p->name);
			$p->position = preg_replace('/\s/', '&nbsp;', $p->position);
			$p->info = '<i class="icon-info"></i>';
			$p->team_id = $team->team_id;
			/*
				Colors
			*/
			// Fictive player color fields used for creating player table.
			$p->HTMLfcolor = '#000000';
			$p->HTMLbcolor = COLOR_HTML_NORMAL;
			if     ($p->is_sold && $DETAILED)   $p->HTMLbcolor = COLOR_HTML_SOLD; # Sold has highest priority.
			elseif ($p->is_dead && $DETAILED)   $p->HTMLbcolor = COLOR_HTML_DEAD;
			elseif ($p->is_mng)                 $p->HTMLbcolor = COLOR_HTML_MNG;
			elseif ($p->is_retired)             $p->HTMLbcolor = COLOR_HTML_RETIRED;
			elseif ($p->is_journeyman_used)     $p->HTMLbcolor = COLOR_HTML_JOURNEY_USED;
			elseif ($p->is_journeyman)          $p->HTMLbcolor = COLOR_HTML_JOURNEY;
			elseif ($p->mayHaveNewSkill())      $p->HTMLbcolor = COLOR_HTML_NEWSKILL;
			elseif ($DETAILED)                  $p->HTMLbcolor = COLOR_HTML_READY;
			$p->skills   = '<small>'.$p->getSkillsStr(true).'</small>';
			$p->injs     = $p->getInjsStr(true);
			$p->position = "<table style='border-spacing:0px;'><tr><td><img align='left' src='$p->icon' alt='player avatar'></td><td>".$lng->getTrn("position/".strtolower($lng->FilterPosition($p->position)))."</td></tr></table>";
			if ($DETAILED) {
				$p->mv_cas = "$p->mv_bh/$p->mv_si/$p->mv_ki";
				$p->mv_spp = "$p->mv_spp/$p->extra_spp";
			}
			// Characteristic's colors
			foreach (array('ma', 'ag', 'pa', 'av', 'st') as $chr) {
				$sub = $p->$chr - $p->{"def_$chr"};
				$defchr = $p->{"def_$chr"};
				if  ($chr == 'ma' || $chr == 'av' || $chr == 'st' ) {
					if ($sub == 0) {
						// Nothing!
					}
					elseif ($sub == 1)  $p->{"${chr}_color"} = COLOR_HTML_CHR_EQP1;
					elseif ($sub > 1)   $p->{"${chr}_color"} = COLOR_HTML_CHR_GTP1;
					elseif ($sub == -1) $p->{"${chr}_color"} = COLOR_HTML_CHR_EQM1;
					elseif ($sub < -1)  $p->{"${chr}_color"} = COLOR_HTML_CHR_LTM1;
					if ($p->$chr != $p->{"${chr}_ua"}) {
						$p->{"${chr}_color"} = COLOR_HTML_CHR_BROKENLIMIT;
						$p->$chr = $p->{$chr.'_ua'}.' <i>('.$p->$chr.' eff.)</i>';
					}
				}
				else {
					if ($defchr > 0) {
						if ($sub == 0) {
							// Nothing!
						}
						elseif ($sub == 1)  $p->{"${chr}_color"} = COLOR_HTML_CHR_EQM1;
						elseif ($sub > 1)   $p->{"${chr}_color"} = COLOR_HTML_CHR_LTM1;
						elseif ($sub == -1) $p->{"${chr}_color"} = COLOR_HTML_CHR_EQP1;
						elseif ($sub < -1)  $p->{"${chr}_color"} = COLOR_HTML_CHR_GTP1;
						if ($p->$chr != $p->{"${chr}_ua"}) {
							$p->{"${chr}_color"} = COLOR_HTML_CHR_BROKENLIMIT;
							$p->$chr = $p->{$chr.'_ua'}.' <i>('.$p->$chr.' eff.)</i>';
						}	
					}
					else {
						if ($sub == 0) {
							// Nothing!
						}
						elseif ($sub == 7)  $p->{"${chr}_color"} = COLOR_HTML_CHR_EQM1;
						elseif ($sub > 7)   $p->{"${chr}_color"} = COLOR_HTML_CHR_LTM1;
						elseif ($sub == 6) $p->{"${chr}_color"} = COLOR_HTML_CHR_EQP1;
						elseif ($sub < 6)  $p->{"${chr}_color"} = COLOR_HTML_CHR_GTP1;
						if ($p->$chr != $p->{"${chr}_ua"}) {
							$p->{"${chr}_color"} = COLOR_HTML_CHR_BROKENLIMIT;
							$p->$chr = $p->{$chr.'_ua'}.' <i>(5 eff.)</i>';
						}	
					}
				}
			}
			if ($p->pa == 0 || $p->pa >6) {       
				$p->pa = '-';
			}
			else {       
				$p->pa = $p->pa.'+';
			}
			$p->seasons = $p->getSeasons();
			if     ($p->is_sold)   				$p->rebuy = 'n/a';
			elseif ($p->is_dead)   				$p->rebuy = 'n/a';
			elseif ($p->is_journeyman_used)     $p->rebuy = 'n/a';
			elseif ($p->is_journeyman)          $p->rebuy = 'n/a';
			else								$p->rebuy = $p->getRebuy();
		}

		/******************************
		 * Team players table
		 * ------------------
		 * Contains player information and menu(s) for skill choice.
		 ******************************/
		$allowEdit = (isset($coach) && $coach)
			? $coach->isMyTeam($team->team_id) || $coach->mayManageObj(T_OBJ_TEAM, $team->team_id)
			: false;
		$fields = array(
			'nr'        => array('desc' => '#', 'editable' => 'updatePlayerNumber', 'javaScriptArgs' => array('team_id', 'player_id'), 'editableClass' => 'number', 'allowEdit' => $allowEdit),
			'name'      => array('desc' => $lng->getTrn('common/name'), 'editable' => 'updatePlayerName', 'javaScriptArgs' => array('team_id', 'player_id'), 'allowEdit' => $allowEdit),
			'info'      => array('desc' => '', 'nosort' => true, 'icon' => true, 'href' => array('link' => urlcompile(T_URL_PROFILE,T_OBJ_PLAYER,false,false,false), 'field' => 'obj_id', 'value' => 'player_id')),
			'position'  => array('desc' => $lng->getTrn('common/pos'), 'nosort' => true),
			'skills'    => array('desc' => $lng->getTrn('common/skills'), 'nosort' => true),
			'injs'      => array('desc' => $lng->getTrn('common/injs'), 'nosort' => true),
			'mv_spp'    => array('desc' => ($DETAILED) ? 'SPP/extra' : 'SPP', 'nosort' => ($DETAILED) ? true : false),
			'value'     => array('desc' => $lng->getTrn('common/value'), 'kilo' => true, 'suffix' => 'k'),
		);
		$fieldsDetailed = array(
			'nr'        => array('desc' => '#', 'editable' => 'updatePlayerNumber', 'javaScriptArgs' => array('team_id', 'player_id'), 'editableClass' => 'number', 'allowEdit' => $allowEdit),
			'name'      => array('desc' => $lng->getTrn('common/name'), 'editable' => 'updatePlayerName', 'javaScriptArgs' => array('team_id', 'player_id'), 'allowEdit' => $allowEdit),
			'info'      => array('desc' => '', 'nosort' => true, 'icon' => true, 'href' => array('link' => urlcompile(T_URL_PROFILE,T_OBJ_PLAYER,false,false,false), 'field' => 'obj_id', 'value' => 'player_id')),
			'position'  => array('desc' => $lng->getTrn('common/pos'), 'nosort' => true),
			'skills'    => array('desc' => $lng->getTrn('common/skills'), 'nosort' => true),
			'injs'      => array('desc' => $lng->getTrn('common/injs'), 'nosort' => true),
			'mv_spp'    => array('desc' => ($DETAILED) ? 'SPP/extra' : 'SPP', 'nosort' => ($DETAILED) ? true : false),
			'value'     => array('desc' => $lng->getTrn('common/value'), 'kilo' => true, 'suffix' => 'k'),
			'seasons'	=> array('desc' => 'Seasons', 'nosort' => true),
			'rebuy'		=> array('desc' => 'Rebuy', 'kilo' => true, 'suffix' => 'k', 'nosort' => true),
		);
		HTMLOUT::sort_table(
			$team->name.' roster',
			urlcompile(T_URL_PROFILE,T_OBJ_TEAM,$team->team_id,false,false).(($DETAILED) ? '&amp;detailed=1' : '&amp;detailed=0'),
			$players,
			($DETAILED) ? $fieldsDetailed : $fields,
			($DETAILED) ? array('+is_dead', '+is_sold', '+is_mng', '+is_retired', '+is_journeyman', '+nr', '+name') : sort_rule('player'),
			(isset($_GET['sort'])) ? array((($_GET['dir'] == 'a') ? '+' : '-') . $_GET['sort']) : array(),
			array('color' => ($DETAILED) ? true : false, 'doNr' => false, 'noHelp' => true)
		);
		?>
		<!-- Following HTML is from class_team_htmlout.php _roster -->
		<table class="text">
			<tr>
				<td style="width: 100%;"> </td>
				<?php
				if ($DETAILED) {
					?>
					<td style="background-color: <?php echo COLOR_HTML_READY;   ?>;"><font color='black'><b>&nbsp;Ready&nbsp;</b></font></td>
					<td style="background-color: <?php echo COLOR_HTML_MNG;     ?>;"><font color='black'><b>&nbsp;MNG&nbsp;</b></font></td>
					<td style="background-color: <?php echo COLOR_HTML_RETIRED;     ?>;"><font color='black'><b>&nbsp;Retired&nbsp;</b></font></td>
					<td style="background-color: <?php echo COLOR_HTML_JOURNEY; ?>;"><font color='black'><b>&nbsp;Journey&nbsp;</b></font></td>
					<td style="background-color: <?php echo COLOR_HTML_JOURNEY_USED; ?>;"><font color='black'><b>&nbsp;Used&nbsp;journey&nbsp;</b></font></td>
					<td style="background-color: <?php echo COLOR_HTML_DEAD;    ?>;"><font color='black'><b>&nbsp;Dead&nbsp;</b></font></td>
					<td style="background-color: <?php echo COLOR_HTML_SOLD;    ?>;"><font color='black'><b>&nbsp;Sold&nbsp;</b></font></td>
					<td style="background-color: <?php echo COLOR_HTML_STARMERC;?>;"><font color='black'><b>&nbsp;Star/merc&nbsp;</b></font></td>
					<td style="background-color: <?php echo COLOR_HTML_NEWSKILL;?>;"><font color='black'><b>&nbsp;New&nbsp;skill&nbsp;</b></font></td>
					<?php
				}
				?>
			</tr>
		</table>
		<?php
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

}

