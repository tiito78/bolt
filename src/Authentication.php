<?php
namespace Bolt;

use Bolt\Translation\Translator as Trans;
use Doctrine\DBAL\DBALException;
use Hautelook\Phpass\PasswordHash;
use Silex;
use Symfony\Component\HttpFoundation\Request;
use UAParser;

/**
 * Authentication handling.
 *
 * @author Gawain Lynch <gawain.lynch@gmail.com>
 */
class Authentication
{
    /** @var \Silex\Application $app */
    private $app;

    /** @var boolean */
    private $validsession;

    public function __construct(Application $app)
    {
        $this->app = $app;

        // Set 'validsession', to see if the current session is valid.
        $this->validsession = $this->checkValidSession();
    }

    /**
     * Check if a given token matches the current (correct) Anit-CSRF-like token.
     *
     * @param string $token
     *
     * @return boolean
     */
    public function checkAntiCSRFToken($token = '')
    {
        if (empty($token)) {
            $token = $this->app['request']->get('bolt_csrf_token');
        }

        if ($token === $this->getAntiCSRFToken()) {
            return true;
        } else {
            $this->app['session']->getFlashBag()->add('error', "The security token was incorrect. Please try again.");

            return false;
        }
    }

    /**
     * We will not allow tampering with sessions, so we make sure the current session
     * is still valid for the device on which it was created, and that the username,
     * ip-address are still the same.
     *
     * @return boolean
     */
    public function checkValidSession()
    {
        if ($this->app['session']->get('user')) {
            $this->app['users']->setCurrentUser($this->app['session']->get('user'));
            $currentuser = $this->app['users']->getCurrentUser();

            if ($database = $this->app['users']->getUser($currentuser['id'])) {
                // Update the session with the user from the database.
                $this->app['users']->setCurrentUser(array_merge($currentuser, $database));
            } else {
                // User doesn't exist anymore
                $this->logout();

                return false;
            }
            if (!$currentuser['enabled']) {
                // user has been disabled since logging in
                $this->logout();

                return false;
            }
        } else {
            // no current user, check if we can resume from authtoken cookie, or return without doing the rest.
            $result = $this->loginAuthtoken();

            return $result;
        }

        $key = $this->getAuthToken($currentuser['username']);

        if ($key != $currentuser['sessionkey']) {
            $this->app['logger.system']->error("Keys don't match. Invalidating session: $key != " . $currentuser['sessionkey'], array('event' => 'authentication'));
            $this->app['logger.system']->info("Automatically logged out user '" . $currentuser['username'] . "': Session data didn't match.", array('event' => 'authentication'));
            $this->logout();

            return false;
        }

        // Check if user is _still_ allowed to log on.
        if (!$this->app['users']->isAllowed('login') || !$currentuser['enabled']) {
            $this->logout();

            return false;
        }

        // Check if there's a bolt_authtoken cookie. If not, set it.
        if (empty($this->authToken)) {
            $this->setAuthtoken();
        }

        return true;
    }

    /**
     * Lookup active sessions.
     *
     * @return array
     */
    public function getActiveSessions()
    {
        $this->deleteExpiredSessions();

        $query = sprintf('SELECT * FROM %s', $this->getTableName('authtoken'));
        $sessions = $this->app['db']->fetchAll($query);

        // Parse the user-agents to get a user-friendly Browser, version and platform.
        $parser = UAParser\Parser::create();

        foreach ($sessions as $key => $session) {
            $ua = $parser->parse($session['useragent']);
            $sessions[$key]['browser'] = sprintf('%s / %s', $ua->ua->toString(), $ua->os->toString());
        }

        return $sessions;
    }

    /**
     * Generate a Anti-CSRF-like token, to use in GET requests for stuff that ought to be POST-ed forms.
     *
     * @return string
     */
    public function getAntiCSRFToken()
    {
        $seed = $this->app['request']->cookies->get('bolt_session');

        if ($this->app['config']->get('general/cookies_use_remoteaddr')) {
            $seed .= '-' . $this->remoteIP;
        }
        if ($this->app['config']->get('general/cookies_use_browseragent')) {
            $seed .= '-' . $this->userAgent;
        }
        if ($this->app['config']->get('general/cookies_use_httphost')) {
            $seed .= '-' . $this->hostName;
        }

        $token = substr(md5($seed), 0, 8);

        return $token;
    }

    /**
     * Return whether or not the current session is valid.
     *
     * @return boolean
     */
    public function isValidSession()
    {
        return $this->validsession;
    }

    /**
     * Attempt to login a user with the given password. Accepts username or email.
     *
     * @param string $user
     * @param string $password
     *
     * @return boolean
     */
    public function login($user, $password)
    {
        //check if we are dealing with an e-mail or an username
        if (false === strpos($user, '@')) {
            return $this->loginUsername($user, $password);
        } else {
            return $this->loginEmail($user, $password);
        }
    }

    /**
     * Attempt to login a user via the bolt_authtoken cookie.
     *
     * @return boolean
     */
    public function loginAuthtoken()
    {
        // If there's no cookie, we can't resume a session from the authtoken.
        if (empty($this->authToken)) {
            return false;
        }

        $authtoken = $this->authToken;
        $remoteip  = $this->remoteIP;
        $browser   = $this->userAgent;

        $this->deleteExpiredSessions();

        // Check if there's already a token stored for this token / IP combo.
        try {
            $query = sprintf('SELECT * FROM %s WHERE token=? AND ip=? AND useragent=?', $this->getTableName('authtoken'));
            $query = $this->app['db']->getDatabasePlatform()->modifyLimitQuery($query, 1);
            $row = $this->app['db']->executeQuery($query, array($authtoken, $remoteip, $browser), array(\PDO::PARAM_STR))->fetch();
        } catch (DBALException $e) {
            // Oops. User will get a warning on the dashboard about tables that need to be repaired.
        }

        // If there's no row, we can't resume a session from the authtoken.
        if (empty($row)) {
            return false;
        }

        $checksalt = $this->getAuthToken($row['username'], $row['salt']);

        if ($checksalt === $row['token']) {
            $user = $this->app['users']->getUser($row['username']);

            $update = array(
                'lastseen'       => date('Y-m-d H:i:s'),
                'lastip'         => $this->remoteIP,
                'failedlogins'   => 0,
                'throttleduntil' => $this->throttleUntil(0)
            );

            // Attempt to update the last login, but don't break on failure.
            try {
                $this->app['db']->update($this->getTableName('users'), $update, array('id' => $user['id']));
            } catch (DBALException $e) {
                // Oops. User will get a warning on the dashboard about tables that need to be repaired.
            }

            $user['sessionkey'] = $this->getAuthToken($user['username']);

            $this->app['session']->set('user', $user);
            $this->app['session']->getFlashBag()->add('success', Trans::__('Session resumed.'));

            $this->app['users']->setCurrentUser($user);

            $this->setAuthToken();

            return true;
        } else {
            // Delete the authtoken cookie.
            setcookie(
                'bolt_authtoken',
                '',
                time() - 1,
                '/',
                $this->app['config']->get('general/cookies_domain'),
                $this->app['config']->get('general/enforce_ssl'),
                true
            );

            return false;
        }
    }

    /**
     * Log out the currently logged in user.
     */
    public function logout()
    {
        $this->app['session']->getFlashBag()->add('info', Trans::__('You have been logged out.'));
        $this->app['session']->remove('user');

        // @see: https://bugs.php.net/bug.php?id=63379
        try {
            $this->app['session']->migrate(true);
        } catch (\Exception $e) {
        }

        // Remove all auth tokens when logging off a user (so we sign out _all_ this user's sessions on all locations)
        try {
            $this->app['db']->delete($this->getTableName('authtoken'), array('username' => $this->app['users']->getCurrentUserProperty('username')));
        } catch (\Exception $e) {
            // Nope. No auth tokens to be deleted. .
        }

        // Remove the cookie.
        setcookie(
            'bolt_authtoken',
            '',
            time() - 1,
            '/',
            $this->app['config']->get('general/cookies_domain'),
            $this->app['config']->get('general/enforce_ssl'),
            true
        );
    }

    /**
     * Handle a password reset confirmation
     *
     * @param string $token
     *
     * @return void
     */
    public function resetPasswordConfirm($token)
    {
        $token .= '-' . str_replace('.', '-', $this->remoteIP);

        $now = date('Y-m-d H:i:s');

        // Let's see if the token is valid, and it's been requested within two hours.
        $query = sprintf('SELECT * FROM %s WHERE shadowtoken = ? AND shadowvalidity > ?', $this->getTableName('users'));
        $query = $this->app['db']->getDatabasePlatform()->modifyLimitQuery($query, 1);
        $user = $this->app['db']->executeQuery($query, array($token, $now), array(\PDO::PARAM_STR))->fetch();

        if (!empty($user)) {

            // allright, we can reset this user.
            $this->app['session']->getFlashBag()->add('success', Trans::__('Password reset successful! You can now log on with the password that was sent to you via email.'));

            $update = array(
                'password'       => $user['shadowpassword'],
                'shadowpassword' => '',
                'shadowtoken'    => '',
                'shadowvalidity' => null
            );
            $this->app['db']->update($this->getTableName('users'), $update, array('id' => $user['id']));
        } else {

            // That was not a valid token, or too late, or not from the correct IP.
            $this->app['logger.system']->error('Somebody tried to reset a password with an invalid token.', array('event' => 'authentication'));
            $this->app['session']->getFlashBag()->add('error', Trans::__('Password reset not successful! Either the token was incorrect, or you were too late, or you tried to reset the password from a different IP-address.'));
        }
    }

    /**
     * Sends email with password request. Accepts email or username
     *
     * @param string $username
     *
     * @return boolean
     */
    public function resetPasswordRequest($username)
    {
        $user = $this->app['users']->getUser($username);

        $recipients = false;

        if (!empty($user)) {
            $shadowpassword = $this->app['randomgenerator']->generateString(12);
            $shadowtoken = $this->app['randomgenerator']->generateString(32);

            $hasher = new PasswordHash($this->hashStrength, true);
            $shadowhashed = $hasher->HashPassword($shadowpassword);

            $shadowlink = sprintf(
                '%s%sresetpassword?token=%s',
                $this->app['paths']['hosturl'],
                $this->app['paths']['bolt'],
                urlencode($shadowtoken)
            );

            // Set the shadow password and related stuff in the database.
            $update = array(
                'shadowpassword' => $shadowhashed,
                'shadowtoken'    => $shadowtoken . '-' . str_replace('.', '-', $this->remoteIP),
                'shadowvalidity' => date('Y-m-d H:i:s', strtotime('+2 hours'))
            );
            $this->app['db']->update($this->getTableName('users'), $update, array('id' => $user['id']));

            // Compile the email with the shadow password and reset link.
            $mailhtml = $this->app['render']->render(
                'mail/passwordreset.twig',
                array(
                    'user'           => $user,
                    'shadowpassword' => $shadowpassword,
                    'shadowtoken'    => $shadowtoken,
                    'shadowvalidity' => date('Y-m-d H:i:s', strtotime('+2 hours')),
                    'shadowlink'     => $shadowlink
                )
            );

            $subject = sprintf('[ Bolt / %s ] Password reset.', $this->app['config']->get('general/sitename'));

            $message = $this->app['mailer']
                ->createMessage('message')
                ->setSubject($subject)
                ->setFrom(array($this->app['config']->get('general/mailoptions/senderMail', $user['email']) => $this->app['config']->get('general/mailoptions/senderName', $this->app['config']->get('general/sitename'))))
                ->setTo(array($user['email'] => $user['displayname']))
                ->setBody(strip_tags($mailhtml))
                ->addPart($mailhtml, 'text/html');

            $recipients = $this->app['mailer']->send($message);

            if ($recipients) {
                $this->app['logger.system']->info("Password request sent to '" . $user['displayname'] . "'.", array('event' => 'authentication'));
            } else {
                $this->app['logger.system']->error("Failed to send password request sent to '" . $user['displayname'] . "'.", array('event' => 'authentication'));
                $this->app['session']->getFlashBag()->add('error', Trans::__("Failed to send password request. Please check the email settings."));
            }
        }

        // For safety, this is the message we display, regardless of whether $user exists.
        if ($recipients === false || $recipients > 0) {
            $this->app['session']->getFlashBag()->add('info', Trans::__('A password reset link has been sent to '%user%'.', array('%user%' => $username)));
        }

        return true;
    }

    /**
     * Attempt to login a user with the given password and email.
     *
     * @param string $email
     * @param string $password
     *
     * @return boolean
     */
    protected function loginEmail($email, $password)
    {
        // for once we don't use getUser(), because we need the password.
        $query = sprintf('SELECT * FROM %s WHERE email=?', $this->getTableName('users'));
        $query = $this->app['db']->getDatabasePlatform()->modifyLimitQuery($query, 1);
        $user = $this->app['db']->executeQuery($query, array($email), array(\PDO::PARAM_STR))->fetch();

        if (empty($user)) {
            $this->app['session']->getFlashBag()->add('error', Trans::__('Username or password not correct. Please check your input.'));

            return false;
        }

        $hasher = new PasswordHash($this->hashStrength, true);

        if ($hasher->CheckPassword($password, $user['password'])) {
            if (!$user['enabled']) {
                $this->app['session']->getFlashBag()->add('error', Trans::__('Your account is disabled. Sorry about that.'));

                return false;
            }

            $this->updateUserLogin($user);

            $this->setAuthToken();

            return true;
        } else {
            $this->loginFailed($user);

            return false;
        }
    }

    /**
     * Attempt to login a user with the given password and username.
     *
     * @param string $username
     * @param string $password
     *
     * @return boolean
     */
    protected function loginUsername($username, $password)
    {
        $userslug = $this->app['slugify']->slugify($username);

        // for once we don't use getUser(), because we need the password.
        $query = sprintf('SELECT * FROM %s WHERE username=?', $this->getTableName('users'));
        $query = $this->app['db']->getDatabasePlatform()->modifyLimitQuery($query, 1);
        $user = $this->app['db']->executeQuery($query, array($userslug), array(\PDO::PARAM_STR))->fetch();

        if (empty($user)) {
            $this->app['session']->getFlashBag()->add('error', Trans::__('Username or password not correct. Please check your input.'));

            return false;
        }

        $hasher = new PasswordHash($this->hashStrength, true);

        if ($hasher->CheckPassword($password, $user['password'])) {
            if (!$user['enabled']) {
                $this->app['session']->getFlashBag()->add('error', Trans::__('Your account is disabled. Sorry about that.'));

                return false;
            }

            $this->updateUserLogin($user);

            $this->setAuthToken();

            return true;
        } else {
            $this->loginFailed($user);

            return false;
        }
    }

    /**
     * Update the user record with latest login information.
     *
     * @param array $user
     */
    protected function updateUserLogin($user)
    {
        $update = array(
            'lastseen'       => date('Y-m-d H:i:s'),
            'lastip'         => $this->remoteIP,
            'failedlogins'   => 0,
            'throttleduntil' => $this->throttleUntil(0)
        );

        // Attempt to update the last login, but don't break on failure.
        try {
            $this->app['db']->update($this->getTableName('users'), $update, array('id' => $user['id']));
        } catch (DBALException $e) {
            // Oops. User will get a warning on the dashboard about tables that need to be repaired.
        }

        $user = $this->app['users']->getUser($user['id']);

        $user['sessionkey'] = $this->getAuthToken($user['username']);

        try {
            $this->app['session']->migrate(true);
        } catch (\Exception $e) {
            // @deprecated remove exception handler in Bolt 3
            // We wish to create a new session-id for extended security, but due
            // to a bug in PHP < 5.4.11, this will throw warnings.
            // Suppress them here. #shakemyhead
            // @see: https://bugs.php.net/bug.php?id=63379
        }

        $this->app['session']->set('user', $user);
        $this->app['session']->getFlashBag()->add('success', Trans::__("You've been logged on successfully."));

        $this->app['users']->setCurrentUser($user);
    }

    /**
     * Add errormessages to logs and update the user
     *
     * @param array $user
     */
    private function loginFailed($user)
    {
        $this->app['session']->getFlashBag()->add('error', Trans::__('Username or password not correct. Please check your input.'));
        $this->app['logger.system']->info("Failed login attempt for '" . $user['displayname'] . "'.", array('event' => 'authentication'));

        // Update the failed login attempts, and perhaps throttle the logins.
        $update = array(
            'failedlogins'   => $user['failedlogins'] + 1,
            'throttleduntil' => $this->throttleUntil($user['failedlogins'] + 1)
        );

        // Attempt to update the last login, but don't break on failure.
        try {
            $this->app['db']->update($this->getTableName('users'), $update, array('id' => $user['id']));
        } catch (DBALException $e) {
            // Oops. User will get a warning on the dashboard about tables that need to be repaired.
        }
    }

    /**
     * Get a key to identify the session with.
     *
     * @param string $name
     * @param string $salt
     *
     * @return string|boolean
     */
    private function getAuthToken($name = '', $salt = '')
    {
        if (empty($name)) {
            return false;
        }

        $seed = $name . '-' . $salt;

        if ($this->app['config']->get('general/cookies_use_remoteaddr')) {
            $seed .= '-' . $this->remoteIP;
        }
        if ($this->app['config']->get('general/cookies_use_browseragent')) {
            $seed .= '-' . $this->userAgent;
        }
        if ($this->app['config']->get('general/cookies_use_httphost')) {
            $seed .= '-' . $this->hostName;
        }

        $token = md5($seed);

        return $token;
    }

    /**
     * Set the Authtoken cookie and DB-entry. If it's already present, update it.
     *
     * @return void
     */
    private function setAuthToken()
    {
        $salt = $this->app['randomgenerator']->generateString(12);
        $token = array(
            'username'  => $this->app['users']->getCurrentUserProperty('username'),
            'token'     => $this->getAuthToken($this->app['users']->getCurrentUserProperty('username'), $salt),
            'salt'      => $salt,
            'validity'  => date('Y-m-d H:i:s', time() + $this->app['config']->get('general/cookies_lifetime')),
            'ip'        => $this->remoteIP,
            'lastseen'  => date('Y-m-d H:i:s'),
            'useragent' => $this->userAgent
        );

        // Update or set the authtoken cookie.
        setcookie(
            'bolt_authtoken',
            $token['token'],
            time() + $this->app['config']->get('general/cookies_lifetime'),
            '/',
            $this->app['config']->get('general/cookies_domain'),
            $this->app['config']->get('general/enforce_ssl'),
            true
        );

        try {
            // Check if there's already a token stored for this name / IP combo.
            $query = sprintf('SELECT id FROM %s WHERE username=? AND ip=? AND useragent=?', $this->getTableName('authtoken'));
            $query = $this->app['db']->getDatabasePlatform()->modifyLimitQuery($query, 1);
            $row = $this->app['db']->executeQuery($query, array($token['username'], $token['ip'], $token['useragent']), array(\PDO::PARAM_STR))->fetch();

            // Update or insert the row.
            if (empty($row)) {
                $this->app['db']->insert($this->getTableName('authtoken'), $token);
            } else {
                $this->app['db']->update($this->getTableName('authtoken'), $token, array('id' => $row['id']));
            }
        } catch (DBALException $e) {
            // Oops. User will get a warning on the dashboard about tables that need to be repaired.
        }
    }

    /**
     * Calculate the amount of time until we should throttle login attempts for a user.
     * The amount is increased exponentially with each attempt: 1, 4, 9, 16, 25, 36, .. seconds.
     *
     * Note: I just realized this is conceptually wrong: we should throttle based on
     * remote_addr, not username. So, this isn't used, yet.
     *
     * @param integer $attempts
     *
     * @return string
     */
    private function throttleUntil($attempts)
    {
        if ($attempts < 5) {
            return null;
        } else {
            $wait = pow(($attempts - 4), 2);

            return date('Y-m-d H:i:s', strtotime("+$wait seconds"));
        }
    }

    private function getTableName($table)
    {
        $prefix = $this->app['config']->get('general/database/prefix', 'bolt_');

        if ($table === 'users') {
            return $prefix . 'users';
        } elseif ($table === 'authtoken') {
            return $prefix . 'authtoken';
        } else {
            throw new \InvalidArgumentException('Invalid table request.');
        }
    }
}
