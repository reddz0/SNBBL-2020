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
    global $rules, $lng, $coach;
	$IS_LOCAL_OR_GLOBAL_ADMIN = (isset($coach) && ($coach->ring == Coach::T_RING_GLOBAL_ADMIN || $coach->ring == Coach::T_RING_LOCAL_ADMIN));

	if (!$IS_LOCAL_OR_GLOBAL_ADMIN) {
		fatal("Sorry. Your access level does not allow you opening the requested page.");
	}
	if (!isset($rules['max_rebuy'])) {
		fatal("Missing rules['max_rebuy'] value in global/league settings file.");
	}
	elseif ($rules['max_rebuy'] == 0 || $rules['max_rebuy'] < -1) {
		fatal("Incorrect value for rules['max_rebuy'] in global/league settings file.");
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
	if (isset($_POST['COMMIT_REBUY']) && isset($_POST['tid'])) {
		if ($_POST['COMMIT_REBUY'] == 'COMMIT REBUY' && is_numeric($_POST['tid'])) {
			self::_doRebuy($_POST['tid']);
		}
	}
    return true;
}

protected static function _teamSelect() 
{
    global $rules, $lng;

    $_SUBMITTED = isset($_POST['team_as']) && $_POST['team_as'];
    $team = '';
    if ($_SUBMITTED) {
        $team = $_POST['team_as'];
    }
    ?>
    <br>
    <center>
	<table class="common" style="width:50%">
		<tr>
			<td style="background-color:#FFFFFF;color:#000000;padding-left:15px;padding-right:15px;">
				<p>What this page WILL do:</p>
				<ul>
					<li>Calculate the rebuy funds.</li>
					<li>Allow rebuy and purchase of additional team goods. Including Rerolls at initial cost.</li>
					<li>Remove all MNG (miss next game) statuses.</li>
					<li>Allow choice of removing NI (niggling injuries).</li>
					<li>Allow firing or rebuying of players.</li>
					<li>Updates team treasury to remaining funds.</li>
				</ul>
				<p>What this page WILL NOT do:</p>
				<ul>
					<li>Preform rebuys for BB Sevens (ie: not implemented).</li>
					<li>Remove STAT injuries. This should be done via the Team Admin Tools.</li>
					<li>Purchase new players. This should be done via the Team Management Tools using remaining funds.</li>
				</ul>
				<p style="color:#298000">Rebuy Funds Capped at <?php if ($rules['max_rebuy'] == -1) { echo 'UNLIMITED'; } else { echo ($rules['max_rebuy'] / 1000) . 'k'; } ?>.</p>
				<p style="color:#FF0000;">WARNING: a completed Team Rebuy CANNOT be undone!</p>
			</td>
		</tr>
	</table>
	<br><br>
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

	/* Argument(s) passed to generating functions. */
	$ALLOW_EDIT = $t->allowEdit(); # Show team action boxes?
	$DETAILED   = true; #(isset($_GET['detailed']) && $_GET['detailed'] == 1);# Detailed roster view?

	/* Team pages consist of the output of these generating functions. */
	$m_error = '';
	list($matches, $pages) = Stats::getMatches(T_OBJ_TEAM, $t->team_id, false, false, false, false, array(), true, true);
	if (is_array($matches) && count($matches) > 0)
		$m_error = 'This team has ' . count($matches) . ' unplayed scheduled matches. Are you sure they are ready to ReBuy?';
	list($players, $players_backup, $jm_error) = self::_loadPlayers($t);
	if ($jm_error !== '' || $m_error !== '') {
		echo '<p><strong>ERRORS FOUND</strong></p><ul>';
		if ($jm_error !== '')
			echo '<li>' . $jm_error . '</li>';
		if ($m_error !== '')
			echo '<li>' . $m_error . '</li>';
		echo '</ul><p><a href="'.urlcompile(T_URL_PROFILE,T_OBJ_TEAM,$t->team_id,false,false).'">Go to Team Page to resolve.</a></p>';
		return false;
	}
	
	echo "<form method='POST' name='rebuy_form'>";
	self::_teamgoods($ALLOW_EDIT, $t, $players);
	self::_roster($ALLOW_EDIT, $DETAILED, $t, $players);
	echo "</form>";
	return true;
}

	protected static function _teamgoods($ALLOW_EDIT, $t, $players) {
		global $settings, $rules, $lng, $coach, $DEA, $racesNoApothecary;
		setupGlobalVars(T_SETUP_GLOBAL_VARS__LOAD_LEAGUE_SETTINGS, array('lid' => $t->f_lid)); // Load correct $rules for league.
		$team = $t; // Copy. Used instead of $this for readability.
		$race = new Race($t->f_race_id);
		$rr_price = $DEA[$race->race]['other']['rr_cost'];
		$apoth = !in_array($race->race_id, $racesNoApothecary);

		?>
		<script type="text/javascript">
			function updateRaisedFunds() {
				var treasury = <?php echo $t->treasury / 1000; ?> + 1000;
				treasury += Number(document.getElementById('num_gp').value) * 20;
				treasury += Number(document.getElementById('num_gw').value) * 20;
				treasury += Number(document.getElementById('num_gd').value) * 10;
				if (<?php echo $rules['max_rebuy'] ?> !== -1) { treasury = Math.min(treasury, <?php echo $rules['max_rebuy'] / 1000 ?>); }
				document.getElementById('rebuy_funds_viz').innerText = treasury;
				document.getElementById('rebuy_funds').value = treasury;
				updateTreasury();
			}
			function updateTreasury() {
				var treasury = Number(document.getElementById('rebuy_funds').value) + Number(document.getElementById('rebuy_delta').value);
				if (<?php echo $rules['max_rebuy'] ?> !== -1) { treasury = Math.min(treasury, <?php echo $rules['max_rebuy'] / 1000 ?>); }
				treasury -= document.getElementById('apo_price').value;
				treasury -= document.getElementById('rr_price').value;
				treasury -= document.getElementById('ac_price').value;
				treasury -= document.getElementById('cl_price').value;
				<?php
				foreach ($players as $p) {
					echo "treasury -= document.getElementById('rebuy_action_".$p->player_id."').value;";
				}
				?>
				document.getElementById('treasury_new_viz').innerText = treasury;
				document.getElementById('treasury_new').value = treasury;
				if (treasury < 0) {
					document.getElementById('COMMIT_REBUY').disabled = true;
					alert("Remaining Treasury is NEGATIVE!");
				} else { document.getElementById('COMMIT_REBUY').disabled = false; }
			}
			function rebuyApo() {
				if (document.getElementById('rebuy_apo').checked) {
					document.getElementById('apo_price_viz').innerText = <?php echo $rules['cost_apothecary'] / 1000 ?>;
					document.getElementById('apo_price').value = <?php echo $rules['cost_apothecary'] / 1000 ?>;
				}
				else {
					document.getElementById('apo_price_viz').innerText = 0;
					document.getElementById('apo_price').value = 0;
				}
				updateTreasury();
			}
			function rebuyRR() {
				var rr_amount = Number(document.getElementById('rebuy_rr').value);
				var rr_max = <?php echo $rules['max_rerolls'] ?>;
				if (rr_amount > rr_max) {
					alert('Value entered exceeds the maximum Rerolls of ' + rr_max + ' allowed!');
					document.getElementById('rebuy_rr').value = 0;
					rr_amount = 0;
				}
				var rr_price = rr_amount * <?php echo $rr_price / 1000; ?>;
				document.getElementById('rr_price_viz').innerText = rr_price;
				document.getElementById('rr_price').value = rr_price;
				updateTreasury();
			}
			function rebuyAC() {
				var ac_amount = Number(document.getElementById('rebuy_ac').value);
				var ac_max = <?php echo $rules['max_ass_coaches'] ?>;
				if (ac_amount > ac_max) {
					alert('Value entered exceeds the maximum Assistant Coaches of ' + ac_max + ' allowed!');
					document.getElementById('rebuy_ac').value = 0;
					ac_amount = 0;
				}
				var ac_price = ac_amount * <?php echo $rules['cost_ass_coaches'] / 1000 ?>;
				document.getElementById('ac_price_viz').innerText = ac_price;
				document.getElementById('ac_price').value = ac_price;
				updateTreasury();
			}
			function rebuyCL() {
				var cl_amount = Number(document.getElementById('rebuy_cl').value);
				var cl_max = <?php echo $rules['max_cheerleaders'] ?>;
				if (cl_amount > cl_max) {
					alert('Value entered exceeds the maximum Cheerleaders of ' + cl_max + ' allowed!');
					document.getElementById('rebuy_cl').value = 0;
					cl_amount = 0;
				}
				var cl_price = cl_amount * <?php echo $rules['cost_cheerleaders'] / 1000 ?>;
				document.getElementById('cl_price_viz').innerText = cl_price;
				document.getElementById('cl_price').value = cl_price;
				updateTreasury();
			}
		</script>

		<input type="hidden" name="tid" id="tid" value="<?php echo $t->team_id; ?>" />
		<table class="common" style="width:50%">
			<tr class="commonhead">
				<td colspan="3"><b>
				<?php echo $t->name;?> Season Record
				</b></td>
			</tr>
			<tr>
				<td><i>Games Played</i></td>
				<td><i>Games Won</i></td>
				<td><i>Games Drawn</i></td>
			</tr>
			<tr>
				<td style="background-color:#FFFFFF;color:#000000;"><input type="text" onchange="numError(this,false);updateRaisedFunds();" size="1" maxlength="2" name="num_gp" value="0" id="num_gp" /></td>
				<td style="background-color:#FFFFFF;color:#000000;"><input type="text" onchange="numError(this,false);updateRaisedFunds();" size="1" maxlength="2" name="num_gw" value="0" id="num_gw" /></td>
				<td style="background-color:#FFFFFF;color:#000000;"><input type="text" onchange="numError(this,false);updateRaisedFunds();" size="1" maxlength="2" name="num_gd" value="0" id="num_gd" /></td>
			</tr>
		</table>
		<p>&nbsp;</p>
		<table class="common" style="width:50%">
			<tr class="commonhead">
				<td colspan="5"><b>
				<?php echo $t->name;?> Treasury
				</b></td>
			</tr>
			<tr>
				<td><i>Item</i></td>
				<td><i>Current Amount</i></td>
				<td><i>Funds Raised</i></td>
				<td><i>Extra</i></td>
				<td><i>Remaining Treasury</i></td>
			</tr>
			<tr>
				<td style="background-color:#FFFFFF;color:#000000;"><b>Treasury</b></td>
				<td style="background-color:#FFFFFF;color:#000000;"><?php echo $t->treasury / 1000; ?>k</td>
				<td style="background-color:#FFFFFF;color:#000000;"><input type="hidden" name="rebuy_funds" id="rebuy_funds" /><span id="rebuy_funds_viz">0</span>k</td>
				<td style="background-color:#FFFFFF;color:#000000;"><input type="text" onchange="numError(this,true);updateTreasury();" size="5" maxlength="10" value="0" name="rebuy_delta" id="rebuy_delta" />k</td>
				<td style="background-color:#FFFFFF;color:#000000;"><input type="hidden" name="treasury_new" id="treasury_new" /><span id="treasury_new_viz">0</span>k</td>
			</tr>
		</table>
		<p>&nbsp;</p>
		<table class="common" style="width:50%">
			<tr class="commonhead">
				<td colspan="6"><b>
				<?php echo $t->name;?> Team Goods
				</b></td>
			</tr>
			<tr>
				<td><i>Item</i></td>
				<td><i>Cost Per</i></td>
				<td><i>Current Amount</i></td>
				<td><i>Current Value</i></td>
				<td><i>Rebuy</i></td>
				<td><i>Cost</i></td>
			</tr>
			<tr>
				<td style="background-color:#FFFFFF;color:#000000;"><b>Dedicated Fans</b></td>
				<td style="background-color:#FFFFFF;color:#000000;">-</td>
				<td style="background-color:#FFFFFF;color:#000000;"><?php echo $t->ff; ?></td>
				<td style="background-color:#FFFFFF;color:#000000;">-</td>
				<td style="background-color:#FFFFFF;color:#000000;">-</td>
				<td style="background-color:#FFFFFF;color:#000000;">-</td>
			</tr>
		<?php
		if ($apoth) {
		?>
			<tr>
				<td style="background-color:#FFFFFF;color:#000000;"><b>Apothecary</b></td>
				<td style="background-color:#FFFFFF;color:#000000;">50k</td>
				<td style="background-color:#FFFFFF;color:#000000;"><?php echo $t->apothecary; ?></td>
				<td style="background-color:#FFFFFF;color:#000000;"><?php echo $t->apothecary * 50; ?>k</td>
				<td style="background-color:#FFFFFF;color:#000000;"><input type="checkbox" name="rebuy_apo" id="rebuy_apo" onchange="rebuyApo();" /></td>
				<td style="background-color:#FFFFFF;color:#000000;"><input type="hidden" name="apo_price" id="apo_price" /><span id="apo_price_viz">0</span>k</td>
			</tr>
		<?php
		}
		?>
			<tr>
				<td style="background-color:#FFFFFF;color:#000000;"><b>Re-rolls</b></td>
				<td style="background-color:#FFFFFF;color:#000000;"><?php echo $rr_price / 1000; ?>k</td>
				<td style="background-color:#FFFFFF;color:#000000;"><?php echo $t->rerolls; ?></td>
				<td style="background-color:#FFFFFF;color:#000000;"><?php echo $t->rerolls * $rr_price / 1000; ?>k</td>
				<td style="background-color:#FFFFFF;color:#000000;"><input type="text" size="2" maxlength="2" onchange="numError(this);rebuyRR();" value="0" name="rebuy_rr" id="rebuy_rr" /></td>
				<td style="background-color:#FFFFFF;color:#000000;"><input type="hidden" name="rr_price" id="rr_price" /><span id="rr_price_viz">0</span>k</td>
			</tr>
			<tr>
				<td style="background-color:#FFFFFF;color:#000000;"><b>Assistant Coaches</b></td>
				<td style="background-color:#FFFFFF;color:#000000;">10k</td>
				<td style="background-color:#FFFFFF;color:#000000;"><?php echo $t->ass_coaches; ?></td>
				<td style="background-color:#FFFFFF;color:#000000;"><?php echo $t->ass_coaches * 10; ?>k</td>
				<td style="background-color:#FFFFFF;color:#000000;"><input type="text" size="2" maxlength="2" onchange="numError(this);rebuyAC();" value="0" name="rebuy_ac" id="rebuy_ac" /></td>
				<td style="background-color:#FFFFFF;color:#000000;"><input type="hidden" name="ac_price" id="ac_price" /><span id="ac_price_viz">0</span>k</td>
			</tr>
			<tr>
				<td style="background-color:#FFFFFF;color:#000000;"><b>Cheerleaders</b></td>
				<td style="background-color:#FFFFFF;color:#000000;">10k</td>
				<td style="background-color:#FFFFFF;color:#000000;"><?php echo $t->cheerleaders; ?></td>
				<td style="background-color:#FFFFFF;color:#000000;"><?php echo $t->cheerleaders * 10; ?>k</td>
				<td style="background-color:#FFFFFF;color:#000000;"><input type="text" size="2" maxlength="2" onchange="numError(this);rebuyCL();" value="0" name="rebuy_cl" id="rebuy_cl" /></td>
				<td style="background-color:#FFFFFF;color:#000000;"><input type="hidden" name="cl_price" id="cl_price" /><span id="cl_price_viz">0</span>k</td>
			</tr>
		</table>
		<p>&nbsp;</p>
		<?php
		
	}

	protected static function _loadPlayers($t) {
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
		setupGlobalVars(T_SETUP_GLOBAL_VARS__LOAD_LEAGUE_SETTINGS, array('lid' => $t->f_lid)); // Load correct $rules for league.
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
			$p->mv_cas = "$p->mv_bh/$p->mv_si/$p->mv_ki";
			$p->mv_spp = "$p->mv_spp/$p->extra_spp";
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
			if ($p->inj_ni > 0)
				$p->heal_ni = "<input type='checkbox' id='heal_ni_".$p->player_id."' name='heal_ni_".$p->player_id."' value='1' />";
			$p->rebuy_action = "<select name='rebuy_action_".$p->player_id."' id='rebuy_action_".$p->player_id."' onchange='updateTreasury();'><option value='0'>Release</option><option value='".($p->rebuy / 1000)."'>Rebuy</option></select>";
		}

		/******************************
		 * Team players table
		 * ------------------
		 * Contains player information and menu(s) for skill choice.
		 ******************************/
		$allowEdit = (isset($coach) && $coach)
			? $coach->isMyTeam($team->team_id) || $coach->mayManageObj(T_OBJ_TEAM, $team->team_id)
			: false;
		$fieldsDetailed = array(
			'nr'        => array('desc' => '#', 'nosort' => true),
			'name'      => array('desc' => $lng->getTrn('common/name'), 'nosort' => true),
			'info'      => array('desc' => '', 'nosort' => true, 'icon' => true, 'href' => array('link' => urlcompile(T_URL_PROFILE,T_OBJ_PLAYER,false,false,false), 'field' => 'obj_id', 'value' => 'player_id')),
			'position'  => array('desc' => $lng->getTrn('common/pos'), 'nosort' => true),
			'ma'        => array('desc' => 'Ma'),
			'st'        => array('desc' => 'St'),
			'ag'        => array('desc' => 'Ag', 'suffix' => '+'),
			'pa'        => array('desc' => 'Pa'),	
			'av'        => array('desc' => 'Av', 'suffix' => '+'),
			'skills'    => array('desc' => $lng->getTrn('common/skills'), 'nosort' => true),
			'injs'      => array('desc' => $lng->getTrn('common/injs'), 'nosort' => true),
			'mv_spp'    => array('desc' => 'SPP/extra', 'nosort' => true),
			'value'     => array('desc' => $lng->getTrn('common/value'), 'kilo' => true, 'suffix' => 'k', 'nosort' => true),
			'seasons'	=> array('desc' => 'Seasons', 'nosort' => true),
			'rebuy'		=> array('desc' => 'Rebuy', 'kilo' => true, 'suffix' => 'k', 'nosort' => true),
			'heal_ni'	=> array('desc' => 'Heal Ni', 'nosort' => true),
			'rebuy_action'	=> array('desc' => $lng->getTrn('common/select'), 'nosort' => true),
		);
		HTMLOUT::sort_table(
			$team->name.' Roster',
			urlcompile(T_URL_PROFILE,T_OBJ_TEAM,$team->team_id,false,false),
			$players,
			$fieldsDetailed,
			sort_rule('player'),
			array(),
			array('color' => false, 'doNr' => false, 'noHelp' => true)
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
		<p>&nbsp;</p>
		<center>
		<p><b>REMEMBER: this action CANNOT be undone!</b></p>
		<input type="submit" name="COMMIT_REBUY" id="COMMIT_REBUY" value="COMMIT REBUY" />
		</center>
		<?php
	}

	protected static function _doRebuy($tid) {
		global $racesNoApothecary;
		$team = new Team($tid);
		title($team->name . ' Team Rebuy');
		$apoth = !in_array($team->f_race_id, $racesNoApothecary);
		?>
		<center>
		<table class="common" style="width:50%">
			<tr>
				<td style="background-color:#FFFFFF;color:#000000;padding-left:15px;padding-right:15px;">
		<?php
		$newsStr = '<p>Seasons End Team Rebuy</p>'; // ('. date('D M d Y') .')
		$newsStr .= '<p>Team Roster:</p>';
		$newsStr .= '<ul>';
		list($players, $players_backup, $jm_error) = self::_loadPlayers($team);
		foreach ($players as $p) {
			$outStr = '(' . $p->nr . ') ' . $p->name . ' : ';
			if ($p->is_retired) {
				$outStr .= ' (Removed Retired) ';
				$p->removeMNG();
			} elseif ($p->is_mng) {
				$outStr .= ' (Removed MNG) ';
				$p->removeMNG();
			}
			if (isset($_POST['heal_ni_'.$p->player_id])) {
				if ($_POST['heal_ni_'.$p->player_id] == 1) {
					$outStr .= ' (Removed NI) ';
					$p->removeNiggle();
				}
			}
			if (isset($_POST['rebuy_action_'.$p->player_id])) {
				if ($_POST['rebuy_action_'.$p->player_id] > 0) {
					$outStr .= ' <span style="color:#298000">(REHIRED)</span> ';
				} else {
					$outStr .= ' <span style="color:#FF0000">(RELEASED)</span> ';
					$p->sell();
				}
			} else {
				$outStr .= ' <span style="color:#FF0000">(RELEASED)</span> ';
				$p->sell();
			}
			$newsStr .= '<li>' . $outStr . '</li>';
		}
		$newsStr .= '</ul>';
		$newsStr .= '<p>Team Goods:</p>';
		$newsStr .= '<ul>';
		$apo_set = 0;
		if (isset($_POST['rebuy_apo']) && $apoth) {
			$newsStr .= '<li>Set Apothecary = ' . ($_POST['rebuy_apo'] == 'on' ? 'YES' : 'NO') . '</li>';
			$apo_set = 1;
		} elseif ($apoth) {
			$newsStr .= '<li>Set Apothecary = NO</li>';
		}
		$newsStr .= '<li>Set Rerolls = ' . $_POST['rebuy_rr'] . '</li>';
		$newsStr .= '<li>Set Assistant Coaches = ' . $_POST['rebuy_ac'] . '</li>';
		$newsStr .= '<li>Set Cheerleaders = ' . $_POST['rebuy_cl'] . '</li>';
		$newsStr .= '<li>Set Treasury = ' . $_POST['treasury_new'] . 'k</li>';
		$newsStr .= '</ul>';
		
		$team_sql = "UPDATE teams SET apothecary = " . $apo_set . ", rerolls = " . $_POST['rebuy_rr'] . ", ass_coaches = " . $_POST['rebuy_ac'] . ", cheerleaders = " . $_POST['rebuy_cl'] . ", treasury = " . ($_POST['treasury_new'] * 1000) . " WHERE team_id = " . $tid . ";";
		mysql_query($team_sql);
		SQLTriggers::run(T_SQLTRIG_TEAM_DPROPS, array('id' => $team->team_id, 'obj' => $team)); # Update TV.
		
		echo $newsStr;
		echo '<p>Writting team news...</p>';
		$team->writeNews($newsStr);
		echo '<p>DONE!</p>';
		echo '<p style="color:#FF0000;">REMINDER: You may need to manually remove STAT injuries to complete Seasons End!</p>';
		echo '<p><a href="' . urlcompile(T_URL_PROFILE,T_OBJ_TEAM,$team->team_id,false,false) . '">Go to Team Roster</a></p>';
		?>
				</td>
			</tr>
		</table>
		</center>
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

