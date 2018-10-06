<?php
/**
 * Action Plugin stylingpages
 *
 * @license    GPL-2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Flávio J. Saraiva <flaviojs2005@gmail.com>
 */

/**
 * Allows users to change the css/js files of this plugin with wikitext.
 *
 * @see README
 */
class action_plugin_stylingpages extends DokuWiki_Action_Plugin
{
    /**
     * Registers callback functions.
     *
     * @param Doku_Event_Handler $controller
     */
    public function register(Doku_Event_Handler $controller)
    {
        $controller->register_hook('COMMON_WIKIPAGE_SAVE', 'BEFORE', $this, 'handleSaveBefore', null, 10);
        $controller->register_hook('COMMON_WIKIPAGE_SAVE', 'AFTER', $this, 'handleSaveAfter', null, 10);
    }

    /**
     * Trigger update when there are no changes.
     */
    public function handleSaveBefore(Doku_Event $event, $param)
    {
        if (!$event->data['contentChanged']) {
            // the AFTER event is not called
            $this->tryUpdate($event);
        }
    }

    /**
     * Trigger update when there are changes.
     */
    public function handleSaveAfter(Doku_Event $event, $param)
    {
        $this->tryUpdate($event);
    }

    /**
     * Update files only if there are file patterns for
     * the page and you are allowed to write in it.
     */
    public function tryUpdate(Doku_Event $event)
    {
        $page = $event->data['id'];

        // get file patterns of the page
        $patterns = array();
        $entries = $this->getConf('page_files');
        foreach ($entries as $entry) {
            $parts = explode('=', $entry, 2); // "page=file_pattern"
            if (count($parts) === 2 && $parts[0] === $page) {
                $pattern = $parts[1];
                if (@preg_match($pattern, '') === false) {
                    msg('Invalid pattern <code>' . hsc($pattern) . '</code>', -1);
                } else {
                    $patterns[] = $parts[1];
                }
            }
        }

        // check update requirements
        if (count($patterns) > 0 && auth_quickaclcheck($page) >= AUTH_WRITE) {
            $wikitext = $event->data['newContent'];
            $this->update($patterns, $wikitext);
        }
    }

    /**
     * Update files matching the patterns
     * with the code blocks in the wikitext.
     */
    public function update($patterns, $wikitext)
    {
        global $conf;
        global $config_cascade;

        $dir = DOKU_PLUGIN . 'stylingpages/';
        $haschanges = false;

        // get file contents from wikitext
        $contents = array(); // array(file => content)
        $instructions = p_get_instructions($wikitext);
        foreach ($instructions as $instruction) {
            // array('code', array(content, format, file), pos)
            if ($instruction[0] === 'code' && count($instruction[1]) > 2) {
                $content = $instruction[1][0];
                $file = $instruction[1][2];
                if (isset($contents[$file])) {
                    $contents[$file] .= $content; // multiple blocks with the same file are appended
                } else {
                    $contents[$file] = $content;
                }
            }
        }

        // delete unreferenced files
        $it = new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS);
        $it = new RecursiveIteratorIterator($it);
        $it->rewind();
        while ($it->valid()) {
            $file = $it->getSubPathName();
            $file = str_replace('\\', '/', $file);
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $file) && !isset($contents[$file])) {
                    $haschanges = true;
                    $path = $dir . $file;
                    $msg = $this->getLang('deleting_file');
                    $msg = str_replace('$1', $file, $msg);
                    msg($msg);
                    @unlink($path);
                }
            }
            $it->next();
        }

        // create or replace referenced files
        foreach ($contents as $file => $content) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $file)) {
                    $haschanges = true;
                    $path = $dir . $file;
                    if (file_exists($path)) {
                        $msg = $this->getLang('replacing_file');
                        $msg = str_replace('$1', $file, $msg);
                        msg($msg);
                    } else {
                        $msg = $this->getLang('creating_file');
                        $msg = str_replace('$1', $file, $msg);
                        msg($msg);
                        @mkdir(dirname($path), $conf['dmode'], true);
                    }
                    $fh = @fopen($path, 'wb') ;
                    @fwrite($fh, $content);
                    @fclose($fh);
                }
            }
        }

        // purge cache (from helper_plugin_extension_extension::purgeCache)
        if ($haschanges) {
            @touch(reset($config_cascade['main']['local']));
        }
    }
}
