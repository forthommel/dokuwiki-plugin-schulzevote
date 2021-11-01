<?php
/**
 * Syntax Plugin:
 * A basic voting system between multiple candidates.
 *
 * the result is determined by the schulze method
 *
 * syntax:
 * <vote right 2010-04-10>
 *  candidate A
 *  candidate B
 *  candidate C
 * </vote>
 *
 * @author Dominik Eckelmann <eckelmann@cosmocode.de>
 * @author Laurent Forthomme <lforthomme.protonmail.com>
 */

// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();
if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once(DOKU_PLUGIN.'syntax.php');

class syntax_plugin_schulzevote_vote extends DokuWiki_Syntax_Plugin {

    function getType(){ return 'substition'; }
    function getPType(){ return 'block'; }
    function getSort(){ return 155; }

    function connectTo($mode) {
         $this->Lexer->addSpecialPattern('<vote\b.*?>\n.*?\n</vote>',$mode,'plugin_schulzevote_vote');
    }

    function handle($match, $state, $pos, Doku_Handler $handler){
        $lines = explode("\n", $match);

        $opts = array(
            'title' => 'vote',
            'date' => null,
            'align' => 'left',
            'admin_users' => array(),
            'admin_groups' => array()
        );

        // Determine date from syntax
        if (preg_match('/ \d{4}-\d{2}-\d{2}/', $lines[0], $opts['date'])) {

            $opts['date'] = strtotime($opts['date'][0]);
            if ($opts['date'] === false || $opts['date'] === -1) {
                $opts['date'] = null;
            }
        }

        if (preg_match('/ adminUsers=([a-zA-Z0-9,]+)/', $lines[0], $admins)) {
            $opts['admin_users'] = explode(',', $admins[1]);
        }

        if (preg_match('/ adminGroups=([a-zA-Z0-9,]+)/', $lines[0], $admins)) {
            $opts['admin_groups'] = explode(',', $admins[1]);
        }

        // Determine poll title
        if (preg_match('/title=([a-zA-Z0-9]+)/', $lines[0], $titles)) {
            $opts['title'] = $titles[1];
        }

        // Determine align informations
        if (preg_match('/(left|right|center)/',$lines[0], $align)) {
            $opts['align'] = $align[0];
        }

        unset($lines[count($lines)-1]);
        unset($lines[0]);

        $candidates = array();

        foreach ($lines as $line) {
            $line = trim($line);
            if (!empty($line)) {
                $candidates[] = $line;
            }
        }

        $hlp = plugin_load('helper', 'schulzevote');
        $hlp->createVote($candidates);
        $this->_export();

        return array('candy' => $candidates, 'opts' => $opts);
    }

    public $opts = array();
    public $candy = array();
    public $data = array();

    function render($mode, Doku_Renderer $renderer, $data) {

        if ($mode != 'xhtml') return false;

        $this->opts = $data['opts'];
        $this->candy = $data['candy'];
        $this->data = $this->_import();

        $renderer->info['cache'] = false;

        if (isset($_POST['already_voted']) && checkSecurityToken()) {
            $this->_handleunvote();
            $this->_export();
        }
        if (isset($_POST['vote']) && checkSecurityToken()) {
            $this->_handlepost();
            $this->_export();
        }
        $this->_html($renderer);
    }

    function _html(&$renderer) {

        global $ID;

        // set alignment
        $align = $this->opts['align'];

        $hlp = plugin_load('helper', 'schulzevote');
#        dbg($hlp);

        // check if the vote is over.
        $open = ($this->opts['date'] !== null) && ($this->opts['date'] > time());
        if ($open) {
            if (!isset($_SERVER['REMOTE_USER'])) {
                $open = false;
                $closemsg = $this->getLang('no_remote_user');
            } elseif ($hlp->hasVoted()) {
                $open = false;
                $closemsg = $this->getLang('already_voted');
            }
        } else {
            $closemsg = $this->getLang('vote_over').'<br />'.
                        $this->_winnerMsg($hlp, 'has_won');
        }

        $form_id = 'plugin__schulzevote__form__' . cleanID($this->opt['title']);

        $form = new Doku_Form(array('id' => $form_id, 'class' => 'plugin__schulzevote plugin_schulzevote_'.$align));
        $form->startFieldset($this->getLang('cast'));
        if ($open) {
            $form->addHidden('id', $ID);
        }
        else {
            $form->addHidden('already_voted', true);
        }

        $form->addElement('<table>');
        $proposals = $this->_buildProposals();
        foreach ($this->candy as $n => $candy) {
            $form->addElement('<tr>');
            $form->addElement('<td>');
            $form->addElement($this->_render($candy));
            $form->addElement('</td>');
            if ($open) {
                $form->addElement('<td>');
                $form->addElement(form_makeListboxField('vote[' . $n . ']',
                                  $proposals,
                                  isset($_POST['vote']) ? $_POST['vote'][$n] : '',
                                  $this->_render($candy),
                                  $n,
                                  $class='block candy'));
                $form->addElement('</td>');
            }
            $form->addElement('</tr>');
        }
        $form->addElement('</table>');

        if ($open) {
            $form->addElement('<p>'.$this->getLang('howto').'</p>');
            $form->addElement(form_makeButton('submit','', $this->getLang('vote')));
            $form->addElement($this->_winnerMsg($hlp, 'leading'));
            $form->addElement('</p>');
        } else {
            $form->addElement(form_makeButton('submit', '', $this->getLang('vote_cancel')));
            $form->addElement('<p>' . $closemsg . '</p>');
        }

        $form->endFieldset();

        // if admin
        if ($this->_isInSuperUsers()) {
            $ranks = array();
            foreach($hlp->getRanking() as $rank => $items) {
                foreach($items as $item) {
                    $ranks[$item] = '<span class="votebar" style="width: ' . (80 / ($rank + 1)) . 'px">&nbsp;</span>';
                }
            }

            $form->startFieldset('');
            $form->addElement('<p>' . $this->_winnerMsg($hlp, 'leading') . '</p>');
            $form->addElement('<table>');
            foreach ($this->candy as $n => $candy) {
                $form->addElement('<tr>');
                $form->addElement('<td>');
                $form->addElement($this->_render($candy));
                $form->addElement('</td>');
                $form->addElement('<td>');
                $form->addElement($ranks[$candy]);
                $form->addElement('</td>');
                $form->addElement('</tr>');
            }
            $form->addElement('</table>');
            $form->endFieldset();
        }

        $renderer->doc .=  $form->getForm();

        return true;
    }

    function _winnerMsg($hlp, $lang) {
        $winner = $hlp->getWinner();
        return !is_null($winner) ? sprintf($this->getLang($lang), $this->_render($winner)) : '';
    }

    function _handlepost() {
        $err = false;
        $err_str = "";
        $max_vote = null;
        foreach($_POST['vote'] as $n => &$vote) {
            if ($vote !== '') {
                $vote = explode(' ', $vote)[0];
                if (!is_numeric($vote)) {
                    $err_str .= "<li>" . $this->render_text(sprintf($this->getLang('invalid_vote'), $this->candy[$n]), 'xhtml') . "</li>";
                    $vote = '';
                    $err = true;
                } else {
                    $vote = (int) $vote;
                    $max_vote = max($vote, $max_vote);
                }
            }
        }
        unset($vote);
        if ($err_str != "")
            msg(sprintf($this->getLang('error_found'), $err_str), -1);
        if ($err || count(array_filter($_POST['vote'])) === 0) return;

        foreach($_POST['vote'] as &$vote) {
            if ($vote === '') {
                $vote = $max_vote + 1;
            }
        }

        $hlp = plugin_load('helper', 'schulzevote');
        if (!$hlp->vote(array_combine($this->candy, $_POST['vote']))) {
            msg($this->getLang('invalidated_vote'), -1);
            return;
        }
        msg($this->getLang('voted'), 1);
    }

    function _handleunvote() {
        $hlp = plugin_load('helper', 'schulzevote');
        $hlp->deleteVote();
        msg($this->getLang('unvoted'), 1);
    }

    function _render($str) {
        return p_render('xhtml', array_slice(p_get_instructions($str), 2, -2), $notused);
    }

    function _isInSuperUsers() {
        global $INFO;

        if (!isset($this->opts['admin_users']) || !isset($this->opts['admin_groups']))
            return false; // ensure backward-compatibility with former polls
        foreach ($this->opts['admin_users'] as $su_user)
            if ($_SERVER['REMOTE_USER'] === $su_user)
                return true;
        foreach ($this->opts['admin_groups'] as $su_group)
            foreach ($INFO['userinfo']['grps'] as $user_group)
                if ($user_group === $su_group)
                    return true;
        return false;
    }

    function _buildProposals() {
        $candy = $this->candy;
        $proposals = range(0, sizeof($candy));
        $proposals[0] = '-';
        if (sizeof($candy) > 0) {
            $proposals[1] = sprintf($this->getLang('first_choice'), $proposals[1]);
            $proposals[sizeof($candy)] = sprintf($this->getLang('last_choice'), $proposals[sizeof($candy)]);
        }
        return $proposals;
    }

    function _voteFilename() {
        if (!isset($this->opts['title'])) {
            return 'vote.vote';
        }
        $title = $this->opts['title'];
        $id = hsc(trim($title));
        return metaFN($id, '.vote');
    }

    function _export() {
        if (!is_array($this->data)) {
            return;
        }
        $filename = $this->_voteFilename();
        uksort($this->data, 'strnatcasecmp');
        io_savefile($filename, serialize($this->data));
        return $filename;
    }

    function _import() {
        $filename = $this->_voteFilename();
        $data = array();
        if (file_exists($filename)) {
            $data = unserialize(file_get_contents($filename));
        }
        echo ">>> ". $filename;
        //sanitize: $doodle[$fullnmae]['choices'] must be at least an array
        //          This may happen if user deselected all choices
        /*foreach($data as $fullname => $userData) {
            if (!is_array($data["$fullname"]['choices'])) {
                $data["$fullname"]['choices'] = array();
            }
        }*/
        uksort($data, "strnatcasecmp"); // case insensitive "natural" sort
        print_r($data);
        return $data;
    }
}

//Setup VIM: ex: et ts=4 enc=utf-8 :
