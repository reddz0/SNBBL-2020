<?php
/*
 *  Copyright (c) Chris Reddy <reddz@mts.net> 2025. All Rights Reserved.
 *
 *
 *  This file is part of OBBLM.
 *
 *  OBBLM is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  OBBLM is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
*/
/*
   This module provides an "advanced" team re-draft interface so you can specify the re-drafting actions at
   end of season without having to manually adjust teams via the Admin Tools on the team roster page.
   
	What this module WILL do:
	- Calculate the rebuy funds.
	- Allow rebuy and purchase of additional team goods. Including Rerolls at initial cost.
	- Remove all MNG (miss next game) statuses.
	- Allow choice of removing NI (niggling injuries).
	- Allow firing or rebuying of players.
	- Updates team treasury to remaining funds.

	What this module WILL NOT do:
	- Preform rebuys for BB Sevens (ie: not implemented).
	- Remove STAT injuries. This should be done via the Team Admin Tools.
	- Purchase new players. This should be done via the Team Management Tools using remaining funds.

	Rebuy Funds can be Capped by setting $rules['max_rebuy'] in the global/local settings files.

	WARNING: a completed Team Rebuy CANNOT be undone!
   
*/

class TeamRebuy implements ModuleInterface
{

	public static function main($argv) # argv = argument vector (array).
	{
		global $rules, $lng, $coach;
		$IS_LOCAL_OR_GLOBAL_ADMIN = (isset($coach) && ($coach->ring == Coach::T_RING_GLOBAL_ADMIN || $coach->ring == Coach::T_RING_LOCAL_ADMIN));

		if (!$IS_LOCAL_OR_GLOBAL_ADMIN) {
			fatal("Sorry. Your access level does not allow you opening the requested page.");
		}
		if (!isset($rules['max_rebuy'])) {
			fatal($lng->getTrn('errors/missing_max_rebuy', __CLASS__));
		}
		elseif ($rules['max_rebuy'] == 0 || $rules['max_rebuy'] < -1) {
			fatal($lng->getTrn('errors/incorrect_max_rebuy', __CLASS__));
		}
		
		$tid = array_shift($argv);
		if (!is_numeric($tid) || $tid == 0) {
			title($lng->getTrn('name', __CLASS__));
			$tid = self::_teamSelect();
		}
		if (is_numeric($tid) && $tid > 0) {
			self::_showteam($tid);
		}
		if (isset($_POST['COMMIT_REBUY']) && isset($_POST['tid'])) {
			if ($_POST['COMMIT_REBUY'] !== '' && is_numeric($_POST['tid'])) {
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
					<?php echo $lng->getTrn('help', __CLASS__); ?>
					<p style="color:#298000"><?php echo $lng->getTrn('fundcap', __CLASS__); ?> <?php if ($rules['max_rebuy'] == -1) { echo 'UNLIMITED'; } else { echo ($rules['max_rebuy'] / 1000) . 'k'; } ?>.</p>
				</td>
			</tr>
		</table>
		<br><br>
		<form method='POST'>
		<?php echo $lng->getTrn('select', __CLASS__); ?> : <input type="text" id='team_as' name="team_as" size="30" maxlength="50" value="<?php echo $team;?>">
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
		<input type="submit" name="start_rebuy" value="<?php echo $lng->getTrn('start', __CLASS__); ?>">
		</form>
		</center>
		<br>
		<?php
		return $_SUBMITTED ? get_alt_col('teams', 'name', $team, 'team_id') : null;
	}

	protected static function _showteam($tid)
	{
		global $coach, $lng;
		
		$t = new Team($tid);

		if (!$coach->isNodeCommish(T_NODE_LEAGUE, $t->f_lid)) {
			fatal("Sorry. Your access level does not allow you opening the requested page.");
		}

		title($t->name . ' ' . $lng->getTrn('name', __CLASS__));

		/* Argument(s) passed to generating functions. */
		$ALLOW_EDIT = $t->allowEdit(); # Show team action boxes?

		/* Check for things that would prevent team from re-draft (ie: not in "end of season" readiness) */
		$m_error = '';
		list($matches, $pages) = Stats::getMatches(T_OBJ_TEAM, $t->team_id, false, false, false, false, array(), true, true);
		if (is_array($matches) && count($matches) > 0)
			$m_error = sprintf($lng->getTrn('errors/unplayed_matches', __CLASS__), count($matches));
		list($players, $players_backup, $jm_error) = self::_loadPlayers($t);
		if ($jm_error !== '' || $m_error !== '') {
			echo '<p><strong>' . $lng->getTrn('errors/errors', __CLASS__) . '</strong></p><ul>';
			if ($jm_error !== '')
				echo '<li>' . $jm_error . '</li>';
			if ($m_error !== '')
				echo '<li>' . $m_error . '</li>';
			echo '</ul><p><a href="'.urlcompile(T_URL_PROFILE,T_OBJ_TEAM,$t->team_id,false,false).'">' . $lng->getTrn('resolvelnk', __CLASS__) . '</a></p>';
			return false;
		}
		
		echo "<form method='POST' name='rebuy_form'>";
		self::_teamgoods($ALLOW_EDIT, $t, $players);
		self::_roster($ALLOW_EDIT, $t, $players);
		echo "</form>";
		return true;
	}

	protected static function _teamgoods($ALLOW_EDIT, $t, $players) {
		global $settings, $rules, $lng, $coach, $DEA, $racesNoApothecary;
		setupGlobalVars(T_SETUP_GLOBAL_VARS__LOAD_LEAGUE_SETTINGS, array('lid' => $t->f_lid)); // Load correct $rules for league.

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
					alert("<?php echo $lng->getTrn('errors/negtreasury', __CLASS__); ?>");
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
					alert('<?php echo $lng->getTrn('errors/maxrr', __CLASS__); ?>');
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
					alert('<?php echo $lng->getTrn('errors/maxac', __CLASS__) ?>');
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
					alert('<?php echo $lng->getTrn('errors/maxcl', __CLASS__) ?>');
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
				<?php echo $t->name;?> <?php echo $lng->getTrn('record', __CLASS__); ?>
				</b></td>
			</tr>
			<tr>
				<td><i><?php echo $lng->getTrn('g_played', __CLASS__); ?></i></td>
				<td><i><?php echo $lng->getTrn('g_won', __CLASS__); ?></i></td>
				<td><i><?php echo $lng->getTrn('g_drawn', __CLASS__); ?></i></td>
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
				<?php echo $t->name;?> <?php echo $lng->getTrn('matches/report/treas'); ?>
				</b></td>
			</tr>
			<tr>
				<td><i><?php echo $lng->getTrn('item', __CLASS__); ?></i></td>
				<td><i><?php echo $lng->getTrn('current', __CLASS__); ?></i></td>
				<td><i><?php echo $lng->getTrn('funds', __CLASS__); ?></i></td>
				<td><i><?php echo $lng->getTrn('extra', __CLASS__); ?></i></td>
				<td><i><?php echo $lng->getTrn('remain_treas', __CLASS__); ?></i></td>
			</tr>
			<tr>
				<td style="background-color:#FFFFFF;color:#000000;"><b><?php echo $lng->getTrn('matches/report/treas'); ?></b></td>
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
				<?php echo $t->name;?> <?php echo $lng->getTrn('team_goods', __CLASS__); ?>
				</b></td>
			</tr>
			<tr>
				<td><i><?php echo $lng->getTrn('item', __CLASS__); ?></i></td>
				<td><i><?php echo $lng->getTrn('costper', __CLASS__); ?></i></td>
				<td><i><?php echo $lng->getTrn('current', __CLASS__); ?></i></td>
				<td><i><?php echo $lng->getTrn('current_v', __CLASS__); ?></i></td>
				<td><i><?php echo $lng->getTrn('rebuyamt', __CLASS__); ?></i></td>
				<td><i><?php echo $lng->getTrn('cost', __CLASS__); ?></i></td>
			</tr>
			<tr>
				<td style="background-color:#FFFFFF;color:#000000;"><b><?php echo $lng->getTrn('matches/report/ff'); ?></b></td>
				<td style="background-color:#FFFFFF;color:#000000;">-</td>
				<td style="background-color:#FFFFFF;color:#000000;"><?php echo $t->rg_ff; ?></td>
				<td style="background-color:#FFFFFF;color:#000000;">-</td>
				<td style="background-color:#FFFFFF;color:#000000;">-</td>
				<td style="background-color:#FFFFFF;color:#000000;">-</td>
			</tr>
		<?php
		if ($apoth) {
		?>
			<tr>
				<td style="background-color:#FFFFFF;color:#000000;"><b><?php echo $lng->getTrn('common/apothecary'); ?></b></td>
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
				<td style="background-color:#FFFFFF;color:#000000;"><b><?php echo $lng->getTrn('common/reroll'); ?></b></td>
				<td style="background-color:#FFFFFF;color:#000000;"><?php echo $rr_price / 1000; ?>k</td>
				<td style="background-color:#FFFFFF;color:#000000;"><?php echo $t->rerolls; ?></td>
				<td style="background-color:#FFFFFF;color:#000000;"><?php echo $t->rerolls * $rr_price / 1000; ?>k</td>
				<td style="background-color:#FFFFFF;color:#000000;"><input type="text" size="2" maxlength="2" onchange="numError(this);rebuyRR();" value="0" name="rebuy_rr" id="rebuy_rr" /></td>
				<td style="background-color:#FFFFFF;color:#000000;"><input type="hidden" name="rr_price" id="rr_price" /><span id="rr_price_viz">0</span>k</td>
			</tr>
			<tr>
				<td style="background-color:#FFFFFF;color:#000000;"><b><?php echo $lng->getTrn('common/ass_coach'); ?></b></td>
				<td style="background-color:#FFFFFF;color:#000000;">10k</td>
				<td style="background-color:#FFFFFF;color:#000000;"><?php echo $t->ass_coaches; ?></td>
				<td style="background-color:#FFFFFF;color:#000000;"><?php echo $t->ass_coaches * 10; ?>k</td>
				<td style="background-color:#FFFFFF;color:#000000;"><input type="text" size="2" maxlength="2" onchange="numError(this);rebuyAC();" value="0" name="rebuy_ac" id="rebuy_ac" /></td>
				<td style="background-color:#FFFFFF;color:#000000;"><input type="hidden" name="ac_price" id="ac_price" /><span id="ac_price_viz">0</span>k</td>
			</tr>
			<tr>
				<td style="background-color:#FFFFFF;color:#000000;"><b><?php echo $lng->getTrn('common/cheerleader'); ?></b></td>
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
		global $settings, $lng;
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
				$error = $lng->getTrn('errors/journeymen', __CLASS__);
				continue;
			}
			array_push($tmp_players, $p);
		}
		$players = $tmp_players;
		return array($players, $players_org, $error);
	}

	protected static function _roster($ALLOW_EDIT, $t, $players) {
		global $rules, $settings, $lng, $skillididx, $coach, $DEA;
		setupGlobalVars(T_SETUP_GLOBAL_VARS__LOAD_LEAGUE_SETTINGS, array('lid' => $t->f_lid)); // Load correct $rules for league.

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
			$p->team_id = $t->team_id;
			/*
				Colors
			*/
			// Fictive player color fields used for creating player table.
			$p->HTMLfcolor = '#000000';
			$p->HTMLbcolor = COLOR_HTML_NORMAL;
			if     ($p->is_sold)   $p->HTMLbcolor = COLOR_HTML_SOLD; # Sold has highest priority.
			elseif ($p->is_dead)   $p->HTMLbcolor = COLOR_HTML_DEAD;
			elseif ($p->is_mng)                 $p->HTMLbcolor = COLOR_HTML_MNG;
			elseif ($p->is_retired)             $p->HTMLbcolor = COLOR_HTML_RETIRED;
			elseif ($p->is_journeyman_used)     $p->HTMLbcolor = COLOR_HTML_JOURNEY_USED;
			elseif ($p->is_journeyman)          $p->HTMLbcolor = COLOR_HTML_JOURNEY;
			elseif ($p->mayHaveNewSkill())      $p->HTMLbcolor = COLOR_HTML_NEWSKILL;
			else   $p->HTMLbcolor = COLOR_HTML_READY;
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
			$p->rebuy_action = "<select name='rebuy_action_".$p->player_id."' id='rebuy_action_".$p->player_id."' onchange='updateTreasury();'><option value='0'>".$lng->getTrn('release', __CLASS__)."</option><option value='".($p->rebuy / 1000)."'>".$lng->getTrn('rebuy', __CLASS__)."</option></select>";
		}

		/******************************
		 * Team players table
		 * ------------------
		 * Contains player information and options for re-draft
		 ******************************/
		$allowEdit = (isset($coach) && $coach)
			? $coach->isMyTeam($t->team_id) || $coach->mayManageObj(T_OBJ_TEAM, $t->team_id)
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
			'heal_ni'	=> array('desc' => 'Heal NI', 'nosort' => true),
			'rebuy_action'	=> array('desc' => $lng->getTrn('common/select'), 'nosort' => true),
		);
		HTMLOUT::sort_table(
			$t->name.' '.$lng->getTrn('common/roster'),
			urlcompile(T_URL_PROFILE,T_OBJ_TEAM,$t->team_id,false,false),
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
				<td style="background-color: <?php echo COLOR_HTML_READY;   ?>;"><font color='black'><b>&nbsp;Ready&nbsp;</b></font></td>
				<td style="background-color: <?php echo COLOR_HTML_MNG;     ?>;"><font color='black'><b>&nbsp;MNG&nbsp;</b></font></td>
				<td style="background-color: <?php echo COLOR_HTML_RETIRED;     ?>;"><font color='black'><b>&nbsp;Retired&nbsp;</b></font></td>
				<td style="background-color: <?php echo COLOR_HTML_JOURNEY; ?>;"><font color='black'><b>&nbsp;Journey&nbsp;</b></font></td>
				<td style="background-color: <?php echo COLOR_HTML_JOURNEY_USED; ?>;"><font color='black'><b>&nbsp;Used&nbsp;journey&nbsp;</b></font></td>
				<td style="background-color: <?php echo COLOR_HTML_DEAD;    ?>;"><font color='black'><b>&nbsp;Dead&nbsp;</b></font></td>
				<td style="background-color: <?php echo COLOR_HTML_SOLD;    ?>;"><font color='black'><b>&nbsp;Sold&nbsp;</b></font></td>
				<td style="background-color: <?php echo COLOR_HTML_STARMERC;?>;"><font color='black'><b>&nbsp;Star/merc&nbsp;</b></font></td>
				<td style="background-color: <?php echo COLOR_HTML_NEWSKILL;?>;"><font color='black'><b>&nbsp;New&nbsp;skill&nbsp;</b></font></td>
			</tr>
		</table>
		<p>&nbsp;</p>
		<center>
		<p><b><?php echo $lng->getTrn('noundo', __CLASS__); ?></b></p>
		<input type="submit" name="COMMIT_REBUY" id="COMMIT_REBUY" value="<?php echo $lng->getTrn('commit', __CLASS__); ?>" onclick="if(!confirm('<?php echo $lng->getTrn('commit_confirm', __CLASS__); ?>')){return false;}"/>
		</center>
		<?php
	}

	protected static function _doRebuy($tid) {
		global $lng, $racesNoApothecary;

		$team = new Team($tid);
		title($team->name . ' ' . $lng->getTrn('name', __CLASS__));

		$apoth = !in_array($team->f_race_id, $racesNoApothecary);

		?>
		<center>
		<table class="common" style="width:50%">
			<tr>
				<td style="background-color:#FFFFFF;color:#000000;padding-left:15px;padding-right:15px;">
		<?php
		$newsStr = '<p>'. $lng->getTrn('end_report', __CLASS__) .'</p>'; // ('. date('D M d Y') .')
		$newsStr .= '<p>' . $lng->getTrn('team_roster', __CLASS__) . ':</p>';
		$newsStr .= '<ul>';
		list($players, $players_backup, $jm_error) = self::_loadPlayers($team);
		foreach ($players as $p) {
			$outStr = '(' . $p->nr . ') ' . $p->name . ' : ';
			if ($p->is_retired) {
				$outStr .= ' (' . $lng->getTrn('removed_retired', __CLASS__) . ') ';
				$p->removeMNG();
			} elseif ($p->is_mng) {
				$outStr .= ' (' . $lng->getTrn('removed_mnd', __CLASS__) . ') ';
				$p->removeMNG();
			}
			if (isset($_POST['heal_ni_'.$p->player_id])) {
				if ($_POST['heal_ni_'.$p->player_id] == 1) {
					$outStr .= ' (' . $lng->getTrn('removed_ni', __CLASS__) . ') ';
					$p->removeNiggle();
				}
			}
			if (isset($_POST['rebuy_action_'.$p->player_id])) {
				if ($_POST['rebuy_action_'.$p->player_id] > 0) {
					$outStr .= ' <span style="color:#298000">(' . $lng->getTrn('rehired', __CLASS__) . ')</span> ';
				} else {
					$outStr .= ' <span style="color:#FF0000">(' . $lng->getTrn('released', __CLASS__) . ')</span> ';
					$p->sell();
				}
			} else {
				$outStr .= ' <span style="color:#FF0000">(' . $lng->getTrn('released', __CLASS__) . ')</span> ';
				$p->sell();
			}
			$newsStr .= '<li>' . $outStr . '</li>';
		}
		$newsStr .= '</ul>';
		$newsStr .= '<p>' . $lng->getTrn('team_goods', __CLASS__) . ':</p>';
		$newsStr .= '<ul>';
		$apo_set = 0;
		if (isset($_POST['rebuy_apo']) && $apoth) {
			$newsStr .= '<li>' . $lng->getTrn('common/apothecary') . ' = ' . ($_POST['rebuy_apo'] == 'on' ? 'YES' : 'NO') . '</li>';
			$apo_set = 1;
		} elseif ($apoth) {
			$newsStr .= '<li>' . $lng->getTrn('common/apothecary') . ' = NO</li>';
		}
		$newsStr .= '<li>' . $lng->getTrn('common/reroll') . ' = ' . $_POST['rebuy_rr'] . '</li>';
		$newsStr .= '<li>' . $lng->getTrn('common/ass_coach') . '= ' . $_POST['rebuy_ac'] . '</li>';
		$newsStr .= '<li>' . $lng->getTrn('common/cheerleader') . ' = ' . $_POST['rebuy_cl'] . '</li>';
		$newsStr .= '<li>' . $lng->getTrn('matches/report/treas') . ' = ' . $_POST['treasury_new'] . 'k</li>';
		$newsStr .= '</ul>';
		
		$team_sql = "UPDATE teams SET apothecary = " . $apo_set . ", rerolls = " . $_POST['rebuy_rr'] . ", ass_coaches = " . $_POST['rebuy_ac'] . ", cheerleaders = " . $_POST['rebuy_cl'] . ", treasury = " . ($_POST['treasury_new'] * 1000) . " WHERE team_id = " . $tid . ";";
		mysql_query($team_sql);
		SQLTriggers::run(T_SQLTRIG_TEAM_DPROPS, array('id' => $team->team_id, 'obj' => $team)); # Update TV.
		
		echo $newsStr;
		echo '<p>' . $lng->getTrn('write_news', __CLASS__) . '</p>';
		$team->writeNews($newsStr);
		echo '<p>DONE!</p>';
		echo '<p style="color:#FF0000;">' . $lng->getTrn('post_info', __CLASS__) . '</p>';
		echo '<p><a href="' . urlcompile(T_URL_PROFILE,T_OBJ_TEAM,$team->team_id,false,false) . '">' . $lng->getTrn('resolvelnk', __CLASS__) . '</a></p>';
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
			'date'       => '2025',
			'setCanvas'  => true,
		);
	}

	public static function getModuleTables()
	{
		return array();
	}

	public static function getModuleUpgradeSQL()
	{
		return array();
	}

	public static function triggerHandler($type, $argv){
	}

}

