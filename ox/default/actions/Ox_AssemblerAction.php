<?php
/**
 *    Copyright (c) 2012 Lunar Logic LLC
 *
 *    This program is free software: you can redistribute it and/or  modify
 *    it under the terms of the GNU Affero General Public License, version 3,
 *    as published by the Free Software Foundation.
 *
 *    This program is distributed in the hope that it will be useful,
 *    but WITHOUT ANY WARRANTY; without even the implied warranty of
 *    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *    GNU Affero General Public License for more details.
 *
 *    You should have received a copy of the GNU Affero General Public License
 *    along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * A system action that assembles like files together.
 *
 * This is the action handler for Assembler Constructs.
 * TODO: Add more documentation here!!!!
 *
 * @copyright Copyright (c) 2012 Lunar Logic LLC
 * @license http://www.gnu.org/licenses/agpl-3.0.html AGPLv3
 */
class Ox_AssemblerAction implements Ox_Routable
{
    /**
     * Turns on object debug logging.
     */
    const DEBUG = TRUE;
    /**
     * directory to find assembler.php (string)
     * @var string
     */
    protected  $asm_dir;
    /**
     * name of the class used in assembler.php (class object)
     * @var string
     */
    protected $asm_class;
    /**
     * Array hash to rename incoming methods (array hash)
     * @var array[]
     */
    protected $method_map;

    /**
     * Action Setup
     *
     * @param null $asm_dir
     * @param null $asm_class
     * @param array $map
     */
    public function __construct($asm_dir = null, $asm_class = null, $map = array())
    {
        $this->asm_dir = $asm_dir;
        $this->asm_class = $asm_class;
        $this->method_map = $map;
    }

    /**
     * This is the "main" function.
     *
     * @param mixed[] $args
     * @return mixed|void
     */
    public function go($args)
    {
        global $security;

        // Used for deep linking if login required.
        $full_path = array_shift($args); // $match[0] from the preg_match in router
        if(!$this->asm_class) {
            $this->asm_class = array_shift($args);
        }

        // Determine the method called.
        $method = 'index';
        if(isset($args[0])) {
            if(strpos($args[0], '/') !== false) {
                $a = explode('/', $args[0]);
                if($a[1]) {
                    $method = $a[1];
                }
            } else {
                $method = $args[0];
            }
            // Remove the method from the args
            array_shift($args);
        }

        // Parse leftover URL parts--these will become the arguments to the
        // function.
        $parsed_args = array();
        foreach($args as $arg) {
            if(strpos($arg, '/') !== false) {
                $a = explode('/', $arg);
                array_push($parsed_args, $a[1]);
            } else {
                array_push($parsed_args, $arg);
            }
        }

        // Check our mapping
        if($this->method_map) {
            if($this->method_map[$method]) {
                $method = $this->method_map[$method];
            }
        }

        // Did we specify a dir other than the default?
        if(!$this->asm_dir) {
            $this->asm_dir = DIR_CONSTRUCT . $this->asm_class;
        }

        $file = $this->asm_dir . DIRECTORY_SEPARATOR . ASSEMBLER_NAME;

        // Begin rendering page
        ob_start();
        //print_r($this->asm_class);
        $assembler = $this->loadAndRunAssembler($full_path, $file, $this->asm_class, $method, $parsed_args);

        // It's template time!
        if($assembler->template && file_exists($template_file = $this->asm_dir . DIRECTORY_SEPARATOR . $assembler->template . '.php')) {
            $assembler->renderTemplate($template_file);
        } else if(file_exists($template_file = $this->asm_dir . DIRECTORY_SEPARATOR . $method . '.php')) {
            $assembler->renderTemplate($template_file);
        }
        //embed the results in a layout
        $content = ob_get_clean();

        if (!$assembler->layout) {
            print $content;
        } else {
            //Ox_LibraryLoader::getResource('security')->debug();
            $layout_file = DIR_LAYOUTS . $assembler->layout . '.php';
            if (file_exists($layout_file)) {
                require_once($layout_file);
            } else {
                //There is no layout file
                print $content;
            }
        }

        exit(0);
    }

    /**
     * Execute the proper method from the select assembler.
     * 
     * @param string $path The path from the web browser.
     * @param string $file The file to be loaded.
     * @param string $asm_class The assembler class.
     * @param string $method The method called (from the path).
     * @param array $parsed_args The arguments included in the url.
     * @return mixed
     */
    private function loadAndRunAssembler($path, $file, $asm_class, $method, $parsed_args)
    {
        $security = Ox_LibraryLoader::getResource('security');
        $db = Ox_LibraryLoader::getResource('db');
        $user = Ox_LibraryLoader::getResource('user');

        if (file_exists($file)) {
            require_once($file);
            $assembler = new $asm_class;
            $assembler->db = $db;
            $assembler->user = $user;

            // Allow for default requires roles if specific requirements are not set for a given action.
            // Does this belong here or should it be moved elsewhere?  Code wise it's easiest to put here,
            // but in terms of functionality and separation of concerns, $security might make more sense.
            if (isset($assembler->roles[$method])) {
                $required_roles = $assembler->roles[$method];
            } else {
                $required_roles = $assembler->default_roles;
            }
            if($security->secureResource($path, $required_roles, $method)) {
                if(self::DEBUG) Ox_Logger::logDebug('Calling method: ' . $method);
                //call_user_func_Array can be slow
                //call_user_func_array(array( $assembler, $method), $parsed_args);
                $this->callAssemblerMethod($assembler, $method, $parsed_args);
                if (isset($assembler->layout)) {
                    $this->layout = $assembler->layout;
                }
                return $assembler;
            } else {
                Ox_Logger::logWarning('AssemblerAction: ACCESS DENIED to path ' . $path . ' for user' . print_r($user,1));
                print "ACCESS DENIED";
            }
        } else {
            Ox_Logger::logError('Could not load assembler: ' . $file);
        }
        return false;
    }

    /**
     * Breaks apart the args and pass them to the method
     *
     * This methed is faster than the straight call to call_user_func_array.
     * .
     * @param $assembler
     * @param $method
     * @param $args
     * @return bool
     */
    private function callAssemblerMethod($assembler, $method, $args)
    {
        switch(count($args)) {
            case 0:
                return $assembler->$method();
            case 1:
                return $assembler->$method($args[0]);
            case 2:
                return $assembler->$method($args[0], $args[1]);
            case 3:
                return $assembler->$method($args[0], $args[1], $args[2]);
            case 4:
                return $assembler->$method($args[0], $args[1], $args[2], $args[3]);
            case 5:
                return $assembler->$method($args[0], $args[1], $args[2], $args[3], $args[4]);
            case 6:
                return $assembler->$method($args[0], $args[1], $args[2], $args[3], $args[4], $args[5]);
            default:
                return call_user_func_array(array( $assembler, $method), $args);
        }
    }
}