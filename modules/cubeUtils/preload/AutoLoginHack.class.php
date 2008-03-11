<?php
/**
 *
 * @package CubeUtils
 * @version $Id: xoops_version.php 1294 2008-01-31 05:32:20Z nobunobu $
 * @copyright Copyright 2006-2008 NobuNobuXOOPS Project <http://sourceforge.net/projects/nobunobuxoops/>
 * @author NobuNobu <nobunobu@nobunobu.com>
 * @license http://www.gnu.org/licenses/gpl.txt GNU GENERAL PUBLIC LICENSE Version 2
 *
 * XOOPS Cube 2.1 Autologin Class (Module Preload Version)
 *
 * This is just a sample for evaluating XOOPS Cube 2.1 Delegate Mechanism.
 * Following Auto Login logic is based on "AutoLogin Hack for XOOPS 2.0.x" by GIJOE
 *   (http://www.peak.ne.jp/xoops/)
 */

class CubeUtils_AutoLoginHack extends XCube_ActionFilter
{
    var $mCookiePath;
    var $mRememberMe = 0;
    var $mLifeTime;

    function preBlockFilter()
    {
        $root =& XCube_Root::getSingleton();

        $config_handler =& xoops_gethandler('config');
        $moduleConfigCubeUtils =& $config_handler->getConfigsByDirname('cubeUtils');

        if ($moduleConfigCubeUtils['cubeUtils_use_autologin']){
        
            //Define custom delegate functions for AutoLogin.
            $root->mDelegateManager->add('Legacy_Controller.SetupUser', array(&$this, 'setupUser'), XCUBE_DELEGATE_PRIORITY_FINAL-1);
            $root->mDelegateManager->add('Site.CheckLogin.Success', array(&$this, 'CheckLoginSuccess'), XCUBE_DELEGATE_PRIORITY_NORMAL-1);
            $root->mDelegateManager->add('Site.Logout',             array(&$this, 'Logout'), XCUBE_DELEGATE_PRIORITY_NORMAL-1);
            $root->mDelegateManager->add('Legacypage.User.Access',  array(&$this, 'AccessToUser'), XCUBE_DELEGATE_PRIORITY_NORMAL-1);

            $this->mCookiePath = defined('XOOPS_COOKIE_PATH') ? XOOPS_COOKIE_PATH : preg_replace( '?http://[^/]+(/.*)$?' , '$1' , XOOPS_URL ) ;
            if( $this->mCookiePath == XOOPS_URL ) $this->mCookiePath = '/' ;
            $this->mLifeTime = $moduleConfigCubeUtils['cubeUtils_login_lifetime'] * 3600;

            $GLOBALS['xoopsAutoLoginEnable'] = true;
        }
    }

    /**
     * Custom 'mSetupUser' Delegate functions for AutoLogin
     *
     */
    function setupUser(&$principal, &$controller, &$context) {
        if (is_object($context->mXoopsUser)) {
            return;
        }
        //Anonymous session
        if (empty($_SESSION['xoopsUserId'])) {
            //Check Cookies for AutoLogin
            $xoopsUser = $this->_getUserFromCookie();
            if (is_object($xoopsUser) && $xoopsUser->getVar('level') > 0) {
                $context->mXoopsUser =& $xoopsUser;
                // Regist to session
                $_SESSION['xoopsUserId'] = $xoopsUser->getVar('uid');
                $_SESSION['xoopsUserGroups'] = $xoopsUser->getGroups();

                $roles = array();
                $roles[] = "Site.RegisteredUser";
                if ($context->mXoopsUser->isAdmin(-1)) {
                    $roles[] = "Site.Administrator";
                }
                if (in_array(XOOPS_GROUP_ADMIN, $_SESSION['xoopsUserGroups'])) {
                    $roles[] = "Site.Owner";
                }

                $identity =& new Legacy_Identity($context->mXoopsUser);
                $principal = new Legacy_GenericPrincipal($identity, $roles);
        
                //
                // Use 'mysession'
                //
                $root =& XCube_Root::getSingleton();
                $xoopsConfig = $root->mContext->mXoopsConfig;
        
                if ($xoopsConfig['use_mysession'] && $xoopsConfig['session_name'] != '') {
                    setcookie($xoopsConfig['session_name'], session_id(), time() + (60 * $xoopsConfig['session_expire']), '/', '', 0);
                }
                // Raise Site.CheckLogin.Success event
                XCube_DelegateUtils::call('Site.CheckLogin.Success', new XCube_Ref($xoopsUser));
            } else { //Invalid AutoLogin
                setcookie('autologin_uname', '', time() - 3600, $this->mCookiePath, '', 0);
                setcookie('autologin_pass', '', time() - 3600, $this->mCookiePath, '', 0);
                if (is_object($xoopsUser)) $xoopsUser = false;
            }
        }
    }

    function &_getUserFromCookie() {
        $root =& XCube_Root::getSingleton();
        $controller = $root->mController;
        //Check Cookies for AutoLogin
        if(isset($_COOKIE['autologin_uname']) && isset($_COOKIE['autologin_pass'])) {
            //Forwarding to confirmation sequence, if request with POST or GET paramaters.
            $confirm_url = '/user.php';
            if(!empty( $_POST)) {
                $_SESSION['AUTOLOGIN_POST'] = $_POST ;
                $_SESSION['AUTOLOGIN_REQUEST_URI'] = $_SERVER['REQUEST_URI'] ;
                $controller->executeForward(XOOPS_URL.$confirm_url.'?op=confirm');
            } else if(!empty($_SERVER['QUERY_STRING']) && substr($_SERVER['SCRIPT_NAME'], -strlen($confirm_url)) != $confirm_url) {
                $_SESSION['AUTOLOGIN_REQUEST_URI'] = $_SERVER['REQUEST_URI'] ;
                $controller->executeForward(XOOPS_URL.$confirm_url.'?op=confirm');
            }
            //Verify UserID and Password in Cookie
            $member_handler =& xoops_gethandler('member');
            $myts =& MyTextSanitizer::getInstance();
            $uname = $myts->stripSlashesGPC($_COOKIE['autologin_uname']);
            $pass = $myts->stripSlashesGPC($_COOKIE['autologin_pass']);

            $criteria = new CriteriaCompo(new Criteria('uname', addslashes($uname)));
            $user_handler =& xoops_gethandler('user');
            $users =& $user_handler->getObjects($criteria, false);
            if( empty( $users ) || count( $users ) != 1 ) {
                $xoopsUser = null ;
            } else {
                $xoopsUser = $users[0];
                //Check Cookie LifeTime;
                $old_limit = time() - $this->mLifeTime ;
                list( $old_Ynj , $old_encpass ) = explode( ':' , $pass ) ;
                if( strtotime( $old_Ynj ) < $old_limit || md5( $xoopsUser->getVar('pass') . $old_Ynj ) != $old_encpass ) {
                    $xoopsUser = false ;
                }
            }
        }
        return $xoopsUser;
    }

    /**
     * Custom 'Site.CheckLogin.Success' Delegate function for AutoLogin
     *
     * @param XoopsUser $xoopsUser
     */
    function CheckLoginSuccess(&$xoopsUser)
    {
        if (is_object($xoopsUser)){
            if ($this->mRememberMe || (!empty($_COOKIE['autologin_uname']) && !empty($_COOKIE['autologin_pass']))) {
                $expire = time() + $this->mLifeTime;
                $Ynj = date('Y-n-j');
                setcookie('autologin_uname', $xoopsUser->getVar('uname'), $expire, $this->mCookiePath, '', 0);
                setcookie('autologin_pass', $Ynj.':'.md5($xoopsUser->getVar('pass').$Ynj), $expire, $this->mCookiePath,'', 0);
            }
        }
    }

    /**
     * Custom 'Site.Logout' Delegate function for AutoLogin
     *
     */
    function Logout()
    {
        // Remove Cookies for Auto login.
        setcookie('autologin_uname', '', time()-3600, $this->mCookiePath, '', 0);
        setcookie('autologin_pass',  '', time()-3600, $this->mCookiePath, '', 0);

        // Remove Cookies for old Auto login.
        setcookie('autologin_uname', '', time()-3600, '/', '', 0);
        setcookie('autologin_pass',  '', time()-3600, '/', '', 0);
    }

    /**
     * Custom 'Legacypage.User.Access' Delegate function for AutoLogin
     *
     */
    function AccessToUser()
    {
        $op=isset($_REQUEST['op']) ? trim($_REQUEST['op']) : 'main';
        $root =& XCube_Root::getSingleton();

        $controller = $root->mController;
        $xoopsUser =& $root->mContext->mXoopsUser;

        switch($op) {
          case 'main':
            if (empty($xoopsUser)) {
                $controller->executeHeader();
                // Using User Module Context (This part is a little bit tricky)
                $controller->setupModuleContext('user'); 
                // Using CubeUtil Module Context (This part is a little bit tricky)
                $root->mLanguageManager->loadModuleMessageCatalog('cubeUtils');
                $context =& $root->mContext;
                $renderTarget =& $context->mModule->getRenderTarget();
                $moduleConfig =  $context->mModuleConfig;
                // Rendering Logon Screen With "Remember Me"
                $renderTarget->setTemplateName('cubeUtils_userform.html');
                if (@isset($_COOKIE[$moduleConfig['usercookie']])) {
                    $renderTarget->setAttribute('usercookie', $_COOKIE[$moduleConfig['usercookie']]);
                }
                if (isset($_GET['xoops_redirect'])) {
                    $renderTarget->setAttribute('xoops_redirect', htmlspecialchars(trim($_GET['xoops_redirect']), ENT_QUOTES));
                }
                $renderTarget->setAttribute('allow_register', $moduleConfig['allow_register']);
                $controller->executeView();
                exit(); //Should not return;
            } else {
                header('Location: '.XOOPS_MODULE_URL.'/user/index.php?action=UserInfo&uid='.$xoopsUser->getVar('uid'));
                exit();
            }
            break;
            
          case 'login':
            if (!empty($_POST['rememberme'])) {
                $this->mRememberMe = 1;
            } else {
                $this->mRememberMe = 0;
            }
            break;

          case 'confirm':
            // 'confirm' action is special for autologin for security checking
            if(!isset( $_SESSION['AUTOLOGIN_REQUEST_URI'])) exit ;
            // get URI
            $url = $_SESSION['AUTOLOGIN_REQUEST_URI'] ;
            unset($_SESSION['AUTOLOGIN_REQUEST_URI']) ;
            if( preg_match('/javascript:/si', $url) ) exit ; // black list of url
            $url4disp = preg_replace('/&amp;/i', '&', htmlspecialchars($url, ENT_QUOTES));

            if( isset( $_SESSION['AUTOLOGIN_POST'] ) ) {
                // For GET request, it require confirmation to continue.
                $old_post = $_SESSION['AUTOLOGIN_POST'] ;
                unset( $_SESSION['AUTOLOGIN_POST'] ) ;

                $hidden_str = '' ;
                foreach( $old_post as $k => $v ) {
                    $hidden_str .= "\t".'      <input type="hidden" name="'.htmlspecialchars($k,ENT_QUOTES).'" value="'.htmlspecialchars($v,ENT_QUOTES).'" />'."\n" ;
                }
                echo '
                <html><head><meta http-equiv="Content-Type" content="text/html; charset='._CHARSET.'" />
                <title>'.$controller->mConfig['sitename'].'</title>
                </head>
                <body>
                <div style="text-align:center; background-color: #EBEBEB; border-top: 1px solid #FFFFFF; border-left: 1px solid #FFFFFF; border-right: 1px solid #AAAAAA; border-bottom: 1px solid #AAAAAA; font-weight : bold;">
                  <h4>Retry Post</h4>
                  <pre>This screen would be a result by some XSS attack.<br />Please be careful to continue.</pre>
                  <form action="'.$url4disp.'" method="POST">
                  '.$hidden_str.'
                    <input type="submit" name="timeout_repost" value="'._SUBMIT.'" />
                  </form>
                </div>
                </body>
                </html>
                ' ;
                exit ;
            } else {
                // For GET request, Do just redirecting
                redirect_header($url4disp, 1, _TAKINGBACK);
                exit();
            }
            break;
        }
    }
}
?>