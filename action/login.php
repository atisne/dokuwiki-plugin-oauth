<?php

use dokuwiki\plugin\oauth\SessionManager;
use OAuth\Common\Http\Exception\TokenResponseException;

/**
 * DokuWiki Plugin oauth (Action Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Gohr <andi@splitbrain.org>
 */
class action_plugin_oauth_login extends DokuWiki_Action_Plugin
{
    /** @var helper_plugin_oauth */
    protected $hlp;

    /**
     * Constructor
     *
     * Initializes the helper
     */
    public function __construct()
    {
        $this->hlp = plugin_load('helper', 'oauth');
    }

    /**
     * Registers a callback function for a given event
     *
     * @param Doku_Event_Handler $controller DokuWiki's event controller object
     * @return void
     */
    public function register(Doku_Event_Handler $controller)
    {
        global $conf;
        if ($conf['authtype'] != 'oauth') return;

        $conf['profileconfirm'] = false; // password confirmation doesn't work with oauth only users

        $controller->register_hook('DOKUWIKI_STARTED', 'BEFORE', $this, 'handleStart');
        $controller->register_hook('HTML_LOGINFORM_OUTPUT', 'BEFORE', $this, 'handleLoginform');
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'handleDoLogin');
    }

    /**
     * Start an oAuth login or restore  environment after successful login
     *
     * @param Doku_Event $event
     * @return void
     */
    public function handleStart(Doku_Event $event)
    {
        global $INPUT;

        // login has been done, but there's environment to be restored
        // TODO when is this the case?
        $sessionManager = SessionManager::getInstance();
        if ($sessionManager->getDo() || $sessionManager->getRev()) {
            $this->restoreSessionEnvironment();
            return;
        }

        // see if a login needs to be started
        $servicename = $INPUT->str('oauthlogin');
        if ($servicename) $this->startOAuthLogin($servicename);
    }

    /**
     * Add the oAuth login links to login form
     *
     * @param Doku_Event $event event object by reference
     * @return void
     */
    public function handleLoginform(Doku_Event $event)
    {
        /** @var Doku_Form $form */
        $form = $event->data;
        $html = '';

        $validDomains = $this->hlp->getValidDomains();

        if (count($validDomains) > 0) {
            $html .= sprintf($this->getLang('eMailRestricted'), join(', ', $validDomains));
        }

        foreach ($this->hlp->listServices() as $service) {
            $html .= $service->loginButton();
        }
        if (!$html) return;

        $form->_content[] = form_openfieldset(
            [
                '_legend' => $this->getLang('loginwith'),
                'class' => 'plugin_oauth',
            ]
        );
        $form->_content[] = $html;
        $form->_content[] = form_closefieldset();
    }

    /**
     * When singleservice is wanted, do not show login, but execute login right away
     *
     * @param Doku_Event $event
     * @return bool
     */
    public function handleDoLogin(Doku_Event $event)
    {
        global $ID;

        if ($event->data != 'login') return true;

        $singleService = $this->getConf('singleService');
        if (!$singleService) return true;

        $enabledServices = $this->hlp->listServices();
        if (count($enabledServices) !== 1) {
            msg($this->getLang('wrongConfig'), -1);
            return false;
        }

        $service = array_shift($enabledServices);

        $url = wl($ID, ['oauthlogin' => $service->getServiceID()], true, '&');
        send_redirect($url);
        return true; // never reached
    }

    /**
     * start the oauth login
     *
     * This will redirect to the external service and stop processing in this request.
     * The second part of the login will happen in auth
     *
     * @see auth_plugin_oauth
     */
    protected function startOAuthLogin($servicename)
    {
        global $ID;
        $service = $this->hlp->loadService($servicename);
        if (is_null($service)) return;

        // remember service in session
        $sessionManager = SessionManager::getInstance();
        $sessionManager->setServiceName($servicename);
        $sessionManager->setPid($ID);
        $sessionManager->saveState();

        try {
            $service->login(); // redirects
        } catch (TokenResponseException $e) {
            $this->hlp->showException($e, 'login failed');
        }
    }

    /**
     * Restore the request environment that had been set before the oauth shuffle
     */
    protected function restoreSessionEnvironment()
    {
        global $INPUT, $ACT, $TEXT, $PRE, $SUF, $SUM, $RANGE, $DATE_AT, $REV;

        $sessionManager = SessionManager::getInstance();
        $ACT = $sessionManager->getDo();
        $_REQUEST = $sessionManager->getRequest();

        $REV = $INPUT->int('rev');
        $DATE_AT = $INPUT->str('at');
        $RANGE = $INPUT->str('range');
        if ($INPUT->post->has('wikitext')) {
            $TEXT = cleanText($INPUT->post->str('wikitext'));
        }
        $PRE = cleanText(substr($INPUT->post->str('prefix'), 0, -1));
        $SUF = cleanText($INPUT->post->str('suffix'));
        $SUM = $INPUT->post->str('summary');

        $sessionManager->setDo('');
        $sessionManager->setRequest([]);
        $sessionManager->saveState();
    }
}
