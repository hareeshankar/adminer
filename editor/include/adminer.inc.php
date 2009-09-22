<?php
class Adminer {
	
	function name() {
		return lang('Editor');
	}
	
	function credentials() {
		return array(); // default INI settings
	}
	
	function database() {
		global $dbh;
		$dbs = get_databases(false);
		return (!$dbs
			? $dbh->result($dbh->query("SELECT SUBSTRING_INDEX(CURRENT_USER, '@', 1)")) // username without the database list
			: $dbs[(information_schema($dbs[0]) ? 1 : 0)] // first available database
		);
	}
	
	function loginForm($username) {
		?>
<table cellspacing="0">
<tr><th><?php echo lang('Username'); ?><td><input type="hidden" name="server" value=""><input name="username" value="<?php echo h($username); ?>">
<tr><th><?php echo lang('Password'); ?><td><input type="password" name="password">
</table>
<?php
	}
	
	function login($login, $password) {
		return true;
	}
	
	function tableName($tableStatus) {
		return h(strlen($tableStatus["Comment"]) ? $tableStatus["Comment"] : $tableStatus["Name"]);
	}
	
	function fieldName($field, $order = 0) {
		return h(strlen($field["comment"]) ? $field["comment"] : $field["field"]);
	}
	
	function selectLinks($tableStatus, $set = "") {
		$TABLE = $tableStatus["Name"];
		if (isset($set)) {
			echo '<p><a href="' . h(ME . 'edit=' . urlencode($TABLE) . $set) . '">' . lang('New item') . "</a>\n";
		}
	}
	
	function backwardKeys($table) {
		global $dbh;
		$return = array();
		$result = $dbh->query("SELECT TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_COLUMN_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = " . $dbh->quote($this->database()) . "
AND REFERENCED_TABLE_SCHEMA = " . $dbh->quote($this->database()) . "
AND REFERENCED_TABLE_NAME = " . $dbh->quote($table) . "
ORDER BY ORDINAL_POSITION"); //! requires MySQL 5
		if ($result) {
			while ($row = $result->fetch_assoc()) {
				$return[$row["TABLE_NAME"]][$row["CONSTRAINT_NAME"]][$row["COLUMN_NAME"]] = $row["REFERENCED_COLUMN_NAME"];
			}
		}
		return $return;
	}
	
	function selectQuery($query) {
		return "<!-- " . str_replace("--", "--><!--", $query) . " -->\n";
	}
	
	function rowDescription($table) {
		// first varchar column
		foreach (fields($table) as $field) {
			if ($field["type"] == "varchar") {
				return idf_escape($field["field"]);
			}
		}
		return "";
	}
	
	function rowDescriptions($rows, $foreignKeys) {
		global $dbh;
		$return = $rows;
		foreach ($rows[0] as $key => $val) {
			foreach ((array) $foreignKeys[$key] as $foreignKey) {
				if (count($foreignKey["source"]) == 1) {
					$id = idf_escape($foreignKey["target"][0]);
					$name = $this->rowDescription($foreignKey["table"]);
					if (strlen($name)) {
						// find all used ids
						$ids = array();
						foreach ($rows as $row) {
							$ids[$row[$key]] = $dbh->quote($row[$key]);
						}
						// uses constant number of queries to get the descriptions, join would be complex, multiple queries would be slow
						$descriptions = array();
						$result = $dbh->query("SELECT $id, $name FROM " . idf_escape($foreignKey["table"]) . " WHERE $id IN (" . implode(", ", $ids) . ")");
						while ($row = $result->fetch_row()) {
							$descriptions[$row[0]] = $row[1];
						}
						// use the descriptions
						foreach ($rows as $n => $row) {
							$return[$n][$key] = $descriptions[$row[$key]];
						}
						break;
					}
				}
			}
		}
		return $return;
	}
	
	function selectVal($val, $link, $field) {
		$return = ($val == "<i>NULL</i>" ? "&nbsp;" : $val);
		if (ereg('blob|binary', $field["type"]) && !is_utf8($val)) {
			$return = lang('%d byte(s)', strlen($val));
			if (ereg("^(GIF|\xFF\xD8\xFF|\x89\x50\x4E\x47\x0D\x0A\x1A\x0A)", $val)) { // GIF|JPG|PNG, getimagetype() works with filename
				$return = "<img src='$link' alt='$return'>";
			}
		}
		if ($field["full_type"] == "tinyint(1)" && $return != "&nbsp;") { // bool
			$return = '<img src="' . ($val ? "../adminer/plus.gif" : "../adminer/cross.gif") . '" alt="' . h($val) . '">';
		}
		return ($link ? "<a href='$link'>$return</a>" : $return);
	}
	
	function editVal($val, $field) {
		if (ereg('date|timestamp', $field["type"])) {
			return preg_replace('~^([0-9]{2}([0-9]+))-(0?([0-9]+))-(0?([0-9]+))~', lang('$1-$3-$5'), $val);
		}
		return $val;
	}
	
	function selectColumnsPrint($select, $columns) {
		//! allow grouping functions by indexes
	}
	
	function selectSearchPrint($where, $columns, $indexes) {
		//! from-to, foreign keys
		echo '<fieldset><legend>' . lang('Search') . "</legend><div>\n";
		$i = 0;
		foreach ((array) $_GET["where"] as $val) {
			if (strlen("$val[col]$val[val]")) {
				echo "<div><select name='where[$i][col]'><option value=''>" . lang('(anywhere)') . optionlist($columns, $val["col"], true) . "</select>";
				echo "<input name='where[$i][val]' value='" . h($val["val"]) . "'></div>\n";
				$i++;
			}
		}
		echo "<div><select name='where[$i][col]' onchange='select_add_row(this);'><option value=''>" . lang('(anywhere)') . optionlist($columns, null, true) . "</select>";
		echo "<input name='where[$i][val]'></div>\n";
		echo "</div></fieldset>\n";
	}
	
	function selectOrderPrint($order, $columns, $indexes) {
		//! desc
		$orders = array();
		foreach ($indexes as $key => $index) {
			$order = array();
			foreach ($index["columns"] as $val) {
				$order[] = $this->fieldName(array("field" => $val, "comment" => $columns[$val]));
			}
			if (count(array_filter($order, 'strlen')) > 1 && $key != "PRIMARY") {
				$orders[$key] = implode(", ", $order);
			}
		}
		if ($orders) {
			echo '<fieldset><legend>' . lang('Sort') . "</legend><div>";
			echo "<select name='index_order'>" . optionlist(array("" => "") + $orders, $_GET["index_order"], true) . "</select>";
			echo "</div></fieldset>\n";
		}
	}
	
	function selectLimitPrint($limit) {
		echo "<fieldset><legend>" . lang('Limit') . "</legend><div>"; // <div> for easy styling
		echo "<select name='limit'>" . optionlist(array("", "30", "100"), $limit) . "</select>";
		echo "</div></fieldset>\n";
	}
	
	function selectLengthPrint($text_length) {
	}
	
	function selectActionPrint() {
		echo "<fieldset><legend>" . lang('Action') . "</legend><div>";
		echo "<input type='submit' value='" . lang('Select') . "'>";
		echo "</div></fieldset>\n";
	}
	
	function selectEmailPrint($emailFields, $columns) {
		global $confirm;
		if ($emailFields) {
			echo '<fieldset><legend><a href="#fieldset-email" onclick="return !toggle(\'fieldset-email\');">' . lang('E-mail') . "</a></legend><div id='fieldset-email'" . ($_POST["email_append"] ? "" : " class='hidden'") . ">\n";
			echo "<p>" . lang('From') . ": <input name='email_from' value='" . h($_POST ? $_POST["email_from"] : $_COOKIE["adminer_email"]) . "'>\n";
			echo lang('Subject') . ": <input name='email_subject' value='" . h($_POST["email_subject"]) . "'>\n";
			echo "<p><textarea name='email_message' rows='15' cols='60'>" . h($_POST["email_message"] . ($_POST["email_append"] ? '{$' . "$_POST[email_addition]}" : "")) . "</textarea><br>\n";
			echo "<select name='email_addition'>" . optionlist($columns, $_POST["email_addition"]) . "</select> <input type='submit' name='email_append' value='" . lang('Insert') . "'>\n"; //! JavaScript
			echo "<p>" . (count($emailFields) == 1 ? '<input type="hidden" name="email_field" value="' . h(key($emailFields)) . '">' : '<select name="email_field">' . optionlist($emailFields) . '</select> ');
			echo "<input type='submit' name='email' value='" . lang('Send') . "'$confirm>\n";
			echo "</div></fieldset>\n";
		}
	}
	
	function selectColumnsProcess($columns, $indexes) {
		return array(array(), array());
	}
	
	function selectSearchProcess($fields, $indexes) {
		$return = array();
		foreach ((array) $_GET["where"] as $val) {
			$col = $val["col"];
			if (strlen("$col$val[val]")) {
				$conds = array();
				foreach ((strlen($col) ? array($col => $fields[$col]) : $fields) as $name => $field) {
					if (strlen($col) || is_numeric($val["val"]) || !ereg('int|float|double|decimal', $field["type"])) {
						$text_type = ereg('char|text|enum|set', $field["type"]);
						$value = $this->processInput($field, (strlen($val["val"]) && $text_type && strpos($val["val"], "%") === false ? "%$val[val]%" : $val["val"]));
						$conds[] = idf_escape($name) . ($value == "NULL" ? " IS" : ($val["op"] != "=" && $text_type ? " LIKE" : " =")) . " $value";
					}
				}
				$return[] = ($conds ? "(" . implode(" OR ", $conds) . ")" : "0");
			}
		}
		return $return;
	}
	
	function selectOrderProcess($fields, $indexes) {
		if ($_GET["order"]) {
			return array(idf_escape($_GET["order"][0]) . (isset($_GET["desc"][0]) ? " DESC" : ""));
		}
		$index_order = $_GET["index_order"];
		foreach ((strlen($index_order) ? array($indexes[$index_order]) : $indexes) as $index) {
			if (strlen($index_order) || $index["type"] == "INDEX") {
				$desc = false;
				foreach ($index["columns"] as $val) {
					if (ereg('date|timestamp', $fields[$val]["type"])) {
						$desc = true;
						break;
					}
				}
				$return = array();
				foreach ($index["columns"] as $val) {
					$return[] = idf_escape($val) . ($desc ? " DESC" : "");
				}
				return $return;
			}
		}
		return array();
	}
	
	function selectLimitProcess() {
		return (isset($_GET["limit"]) ? $_GET["limit"] : "30");
	}
	
	function selectLengthProcess() {
		return "100";
	}
	
	function selectEmailProcess($where, $foreignKeys) {
		global $dbh;
		if ($_POST["email_append"]) {
			return true;
		}
		if ($_POST["email"]) {
			$sent = 0;
			if ($_POST["all"] || $_POST["check"]) {
				$field = idf_escape($_POST["email_field"]);
				$subject = $_POST["email_subject"];
				$message = $_POST["email_message"];
				preg_match_all('~\\{\\$([a-z0-9_]+)\\}~i', "$subject.$message", $matches); // allows {$name} in subject or message
				$result = $dbh->query("SELECT DISTINCT $field, " . implode(", ", array_map('idf_escape', array_unique($matches[1]))) . " FROM " . idf_escape($_GET["select"])
					. " WHERE $field IS NOT NULL AND $field != ''"
					. ($where ? " AND " . implode(" AND ", $where) : "")
					. ($_POST["all"] ? "" : " AND ((" . implode(") OR (", array_map('where_check', (array) $_POST["check"])) . "))")
				);
				$rows = array();
				while ($row = $result->fetch_assoc()) {
					$rows[] = $row;
				}
				foreach ($this->rowDescriptions($rows, $foreignKeys) as $row) {
					$replace = array();
					foreach ($matches[1] as $val) {
						$replace['{$' . "$val}"] = $row[$val]; //! allow literal {$name}
					}
					$email = $row[$_POST["email_field"]];
					if (is_email($email) && mail($email, email_header(strtr($subject, $replace)), strtr($message, $replace),
						"MIME-Version: 1.0\nContent-Type: text/plain; charset=utf-8\nContent-Transfer-Encoding: 8bit"
						. (is_email($_POST["email_from"]) ? "\nFrom: $_POST[email_from]" : "") //! should allow address with a name but simple application of email_header() adds the default server domain
					)) {
						$sent++;
					}
				}
			}
			cookie("adminer_email", $_POST["email_from"]);
			redirect(remove_from_uri(), lang('%d e-mail(s) have been sent.', $sent));
		}
		return false;
	}
	
	function messageQuery($query) {
		return "<!--\n" . str_replace("--", "--><!--", $query) . "\n-->";
	}
	
	function editFunctions($field) {
		$return = array("" => ($field["null"] || $field["auto_increment"] ? "" : "*"));
		if (ereg('date|time', $field["type"])) {
			$return[] = "now";
		}
		if (eregi('_(md5|sha1)$', $field["field"], $match)) {
			$return[] = strtolower($match[1]);
		}
		return $return;
	}
	
	function editInput($table, $field, $attrs, $value) {
		global $dbh;
		$foreign_keys = column_foreign_keys($table);
		foreach ((array) $foreign_keys[$field["field"]] as $foreign_key) {
			if (count($foreign_key["source"]) == 1) {
				$id = idf_escape($foreign_key["target"][0]);
				$name = $this->rowDescription($foreign_key["table"]);
				if (strlen($name) && $dbh->result($dbh->query("SELECT COUNT(*) FROM " . idf_escape($foreign_key["table"]))) <= 1000) { // optionlist with more than 1000 options would be too big
					$return = array("" => "");
					$result = $dbh->query("SELECT $id, $name FROM " . idf_escape($foreign_key["table"]) . " ORDER BY 2");
					while ($row = $result->fetch_row()) {
						$return[$row[0]] = $row[1];
					}
					return "<select$attrs>" . optionlist($return, $value, true) . "</select>";
				}
			}
		}
		if ($field["full_type"] == "tinyint(1)") { // bool
			return '<input type="checkbox" value="' . h($value ? $value : 1) . '"' . ($value ? ' checked' : '') . "$attrs>";
		}
		if (ereg('date|timestamp', $field["type"])) {
			return "<input value='" . h($value) . "'$attrs> (" . lang('yyyy-mm-dd') . ")"; //! maxlength
		}
		return '';
	}
	
	function processInput($field, $value, $function = "") {
		global $dbh;
		if ($function == "now") {
			return "$function()";
		}
		$return = $dbh->quote(ereg('date|timestamp', $field["type"]) && preg_match('(^' . str_replace('\\$1', '(?P<p1>[0-9]+)', preg_replace('~(\\\\\\$([2-6]))~', '(?P<p\\2>[0-9]{1,2})', preg_quote(lang('$1-$3-$5')))) . '(.*))', $value, $match)
			? ($match["p1"] ? $match["p1"] : ($match["p2"] < 70 ? 20 : 19) . $match["p2"]) . "-$match[p3]$match[p4]-$match[p5]$match[p6]" . end($match)
			: $value
		);
		if (!ereg('varchar|text', $field["type"]) && $field["full_type"] != "tinyint(1)" && !strlen($value)) {
			$return = "NULL";
		}
		return $return;
	}
	
	function navigation($missing) {
		global $VERSION;
		?>
<h1>
<a href="http://www.adminer.org/" id="h1"><?php echo $this->name(); ?></a>
<span class="version"><?php echo $VERSION; ?></span>
<a href="http://www.adminer.org/editor/#download" id="version"><?php echo (version_compare($VERSION, $_COOKIE["adminer_version"]) < 0 ? h($_COOKIE["adminer_version"]) : ""); ?></a>
</h1>
<?php
		if ($missing != "auth") {
			?>
<form action="" method="post">
<p>
<input type="hidden" name="token" value="<?php echo $_SESSION["tokens"][$_GET["server"]]; ?>">
<input type="submit" name="logout" value="<?php echo lang('Logout'); ?>">
</p>
</form>
<?php
			$this->printTables($missing);
		}
	}
	
	function printTables($missing) {
		if ($missing != "db") {
			$table_status = table_status();
			if (!$table_status) {
				echo "<p class='message'>" . lang('No tables.') . "\n";
			} else {
				echo "<p id='tables'>\n";
				foreach ($table_status as $row) {
					$name = $this->tableName($row);
					if (isset($row["Engine"]) && strlen($name)) { // ignore views and tables without name
						echo "<a href='" . h(ME) . 'select=' . urlencode($row["Name"]) . "'>$name</a><br>\n";
					}
				}
			}
		}
	}
	
}

$adminer = (function_exists('adminer_object') ? adminer_object() : new Adminer);
