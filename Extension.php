<?php
// PasswordProtect Extension for Bolt

namespace Bolt\Extension\Bolt\PasswordProtect;

use Hautelook\Phpass\PasswordHash;
use Bolt\Library as Lib;
use Symfony\Component\HttpFoundation\Request;
use Silex\Application as SilexApplication;
use Bolt\Menu\MenuEntry;
use Symfony\Component\Yaml\Dumper;
use Symfony\Component\Yaml\Parser;

class Extension extends \Bolt\BaseExtension
{
    public function getName()
    {
        return "Password Protect";
    }

    public function initialize()
    {

        if (empty($this->config['encryption'])) {
            $this->config['encryption'] = "plaintext";
        }

        $this->addTwigFunction('passwordprotect', 'passwordProtect');
        $this->addTwigFunction('passwordform', 'passwordForm');

        $path = $this->app['config']->get('general/branding/path') . '/generatepasswords';
        $this->app->match($path, array($this, "generatepasswords"));

        $extension = $this;

        // Register this extension's actions as an early event.
        $this->app->before(function (Request $request) use ($extension) {
            return $extension->handleRequest($request);
        }, SilexApplication::EARLY_EVENT);

        $this->addMenuOption('Edit Access Code', $this->app['resources']->getUrl('bolt').'generatepasswords', 'fa:pencil-square-o');

    }

    public function handleRequest(Request $request)
    {
        $path = explode("/", $request->getPathInfo());

        if (isset($path[1])) {
            if ($path[1] === $this->config['contentType']) {
                if ($this->app['session']->get('passwordprotect') == 1) {
                    return true;
                } else {
                    $redirectto = $this->app['storage']->getContent($this->config['redirect'], array('returnsingle' => true));
                    $returnto = $this->app['request']->getRequestUri();
                    $redirect = Lib::simpleredirect('/~hope/web'.$redirectto->link(). "?returnto=" . urlencode($returnto));
                    //return $this->app->redirect($redirectto->link(). "?returnto=" . urlencode($returnto));
                }
            }
        }

    }

    /**
     * Check if we're currently allowed to view the page. If not, redirect to
     * the password page.
     *
     * @return \Twig_Markup
     */
    public function passwordProtect()
    {

        if ($this->app['session']->get('passwordprotect') == 1) {
            return new \Twig_Markup("<!-- Password protection OK! -->", 'UTF-8');
        } else {

            $redirectto = $this->app['storage']->getContent($this->config['redirect'], array('returnsingle' => true));
            $returnto = $this->app['request']->getRequestUri();

            $redirect = Lib::simpleredirect($redirectto->link(). "?returnto=" . urlencode($returnto));

            // Yeah, this isn't very nice, but we _do_ want to shortcircuit the request.
            die();
        }
    }

    /**
     * Show the password form. If the visitor gives the correct password, they
     * are redirected to the page they came from, if any.
     *
     * @return \Twig_Markup
     */
    public function passwordForm()
    {

        // Set up the form.
        $form = $this->app['form.factory']->createBuilder('form');

        if ($this->config['password_only'] == false) {
            $form->add('username', 'text');
        }

        $form->add('password', 'password', [
            'attr' => ['placeholder' => 'Access Code'],
            'label' => false
        ]);
        $form = $form->getForm();

        if ($this->app['request']->getMethod() == 'POST') {

            $form->bind($this->app['request']);

            $data = $form->getData();

            if ($form->isValid() && $this->checkLogin($data)) {

                // Set the session var, so we're authenticated..
                $this->app['session']->set('passwordprotect', 1);
                $this->app['session']->set('passwordprotect_name', $this->checkLogin($data));

                // Print a friendly message..
                printf("<p class='message-correct'>%s</p>", $this->config['message_correct']);

                $returnto = $this->app['request']->get('returnto');

                // And back we go, to the page we originally came from..
                if (!empty($returnto)) {
                    //return $this->app->redirect($returnto);
                    Lib::simpleredirect($returnto);
                    //die();
                }

            } else {

                // Remove the session var, so we can test 'logging off'..
                $this->app['session']->remove('passwordprotect');
                $this->app['session']->remove('passwordprotect_name');

                // Print a friendly message..
                if(!empty($data['password'])) {
                    printf("<p class='message-wrong'>%s</p>", $this->config['message_wrong']);
                }

            }

        }

        if (! empty($this->config['form'])) {
            $formView = $this->config['form'];
        } else {
            $formView = 'assets/passwordform';
        }

        // Render the form, and show it it the visitor.
        $this->app['twig.loader.filesystem']->addPath(__DIR__);
        $html = $this->app['twig']->render($formView, array('form' => $form->createView()));

        return new \Twig_Markup($html, 'UTF-8');

    }

    /**
     * Allow users to place {{ passwordprotect() }} tags into content, if
     * `allowtwig: true` is set in the contenttype.
     *
     * @return boolean
     */
    public function isSafe()
    {
        return true;
    }

    /**
     * Check if users can be logged on.
     *
     * @return boolean
     */
    private function checkLogin($data)
    {

        if (empty($data['password'])) {
            return false;
        }

        $hasher = new PasswordHash(12, true);

        // dump($this->config);

        // If we only use the password, the 'users' array is just one element.
        if ($this->config['password_only']) {
            $visitors = array('visitor' => $this->config['password']);
            $data['username'] = 'visitor';
        } else {
            $visitors = $this->config['visitors'];
        }

        foreach ($visitors as $visitor => $password) {
            if ($data['username'] === $visitor) {
                // echo "user match!";
                if (($this->config['encryption'] == 'md5') && (md5($data['password']) === $password)) {
                    return $visitor;
                } elseif (($this->config['encryption'] == 'password_hash') && $hasher->CheckPassword($data['password'], $password)) {
                    return $visitor;
                } elseif (($this->config['encryption'] == 'plaintext') && ($data['password'] === $password))  {
                    return $visitor;
                }
            }
        }

        // If we get here, no dice.
        return false;

    }

    public function passwordGenerator($password)
    {
        switch($this->config['encryption']) {
            case 'plaintext':
                $password = $password;
                break;
            case 'md5':
                $password = md5($password);
                break;
            case 'password_hash':
                $hasher = new PasswordHash(12, true);
                $password = $hasher->HashPassword($password);
                break;
        }

        return $password;
    }


    public function generatepasswords()
    {

        if (!$this->app['users']->isAllowed('dashboard')) {
            die('You do not have the right privileges to view this page.');
        }

        // Set up the form.
        $form = $this->app['form.factory']->createBuilder('form');
        $form->add('password', 'password');
        $form = $form->getForm();

        $configData = $this->read();

        $oldPassword = false;

        if (isset($configData['password'])) {
            $oldPassword = $configData['password'];
        }

        $password = false;

        if ($this->app['request']->getMethod() == 'POST') {
            $form->bind($this->app['request']);
            $data = $form->getData();
            if ($form->isValid()) {

                if (isset($configData['password'])) {
                    $plainPassword = $data['password'];
                    $hashedPassword = $this->passwordGenerator($plainPassword);
                    $configData['password'] = $hashedPassword;
                    $this->write($configData);
                }

            }
        }

        // Render the form, and show it it the visitor.
        $this->app['twig.loader.filesystem']->addPath(__DIR__);
        $html = $this->app['twig']->render(
            'assets/passwordgenerate.twig',
            array(
                'form' => $form->createView(),
                'password' => $plainPassword,
                'oldPassword' => $oldPassword
            )
        );

        return new \Twig_Markup($html, 'UTF-8');

    }

    /**
     * Handles reading the Bolt Forms yml file.
     *
     * @return array The parsed data
     */
    protected function read()
    {
        $file = $this->app['resources']->getPath('config/extensions/passwordprotect.bolt.yml');
        $yaml = file_get_contents($file);
        $parser = new Parser();
        $data = $parser->parse($yaml);
        return $data;
    }

    /**
     * Internal method that handles writing the data array back to the YML file.
     *
     * @param array $data
     *
     * @return bool True if successful
     */
    protected function write($data)
    {
        $dumper = new Dumper();
        $dumper->setIndentation(2);
        $yaml = $dumper->dump($data, 9999);
        $file = $this->app['resources']->getPath('config/extensions/passwordprotect.bolt.yml');
        try {
            $response = @file_put_contents($file, $yaml);
        } catch (\Exception $e) {
            $response = null;
        }
        return $response;
    }

}
