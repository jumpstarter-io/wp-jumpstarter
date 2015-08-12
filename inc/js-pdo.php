<?php

/*
 * JS subclasses of the WP sqlite integration package to enable arbitrarily
 * long transactions, ie: more than one statement.
 *
 * Tested with WP sqlite integration version 1.7.
 */

// Don't load directly.
if (!defined("ABSPATH"))
    die("-1");

$sqlite_rel_path = "/../../sqlite-integration/";

require_once(dirname(__FILE__) . $sqlite_rel_path . "pdoengine.class.php");
require_once(dirname(__FILE__) . $sqlite_rel_path . "pdodb.class.php");

if (!class_exists("JSPDOEngine") && !class_exists("JSPDODB")) {

    function js_pdo_log($str = "") {
        if ((defined("JS_DEBUG") && JS_DEBUG) && function_exists("js_log")) {
            js_log($str);
        }
    }

    class JSPDOEngine extends PDOEngine {

        private $delayedCommit = false;

        public function __construct() {
            js_pdo_log("JSPDOEngine::__construct");
            parent::__construct();
        }

        public function begin() {
            js_pdo_log("JSPDOEngine::begin: delaying commit");
            $this->delayedCommit = true;
            if (!$this->beginTransaction()) {
                throw new Exception("JSPDOEngine::begin: could not start transaction");
            }
        }

        public function commit($force = false) {
            if (!$this->delayedCommit || ($this->delayedCommit && $force === true)) {
                js_pdo_log("JSPDOEngine::commit: committing data");
                parent::commit();
            } else {
                js_pdo_log("JSPDOEngine::commit: not committing. delayed commit requested");
            }
        }

    }

    class JSPDODB extends PDODB {

        public function __construct() {
            js_pdo_log("JSPDODB::__construct");
            parent::__construct();
            $this->show_errors();
        }

        public function db_connect($allow_bail = true) {
            js_pdo_log("JSPDODB::db_connect");
            $this->dbh = new JSPDOEngine();
            if (!$this->dbh) {
                wp_load_translations_early(); //probably there's no translations
                $this->bail(sprintf(__("<h1>Error establishing a database connection</h1><p>We have been unable to connect to the specified database. <br />The error message received was %s"), $this->dbh->errorInfo()));
                return;
            }
            $is_enabled_foreign_keys = @$this->get_var('PRAGMA foreign_keys');
            if ($is_enabled_foreign_keys == '0')
                @$this->query('PRAGMA foreign_keys = ON');
            $this->has_connected = true;
            $this->ready = true;
        }

        public function begin() {
            js_pdo_log("JSPDODB::begin");
            $this->dbh->begin();
        }

        public function commit() {
            js_pdo_log("JSPDODB::commit");
            //$this->dbh->endTransaction();
            try {
                $this->dbh->commit(true);
            } catch (PDOException $e) {
                $reason = $e->getCode();
                $message = $e->getMessage();
                $this->dbh->rollBack();
                throw new Exception("JSPDODB::commit: transaction error: $reason, $message");
            }
        }

    }

}
