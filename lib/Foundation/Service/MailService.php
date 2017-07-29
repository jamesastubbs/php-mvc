<?php

namespace PHPMVC\Foundation\Service;

use PHPMVC\Foundation\Exception\ServiceDependencyException;
use PHPMVC\Foundation\Interfaces\ServiceInterface;
use PHPMVC\Foundation\Interfaces\ServiceableInterface;
use PHPMVC\Foundation\Services;

class MailService implements ServiceInterface, ServiceableInterface
{
    /**
     * @var  Services
     */
    private $services = null;

    /**
     * {@inheritdoc}
     */
    public function onServiceStart()
    {
        $configService = $this->services->get('app.config', true);

        if ($configService->get('mail') === null) {
            throw new ServiceDependencyException(
                'Cannot send any mail as the settings have not been configured.'
            );
        }
    }

    public function setServices(Services $services)
    {
        $this->services = $services;
    }

    public function send(array $toAddresses, $subject, $body, $isHtml = false, $callback = null)
    {
        $configService = $this->services->get('app.config');
        $mailConfig = $configService->get('mail');

        $mailer = new \PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host = $mailConfig['host'];
        $mailer->Port = $mailConfig['port'];
        $mailer->Username = $mailConfig['username'];
        $mailer->Password = $mailConfig['password'];
        $mailer->SMTPSecure = isset($mailConfig['encryption']) ? $mailConfig['encryption'] : 'none';
        $mailer->SMTPAuth = isset($mailConfig['auth']) ? $mailConfig['auth'] : false;

        $mailer->From = $mailConfig['fromEmail'];
        $mailer->FromName = $mailConfig['fromName'];

        foreach ($toAddresses as $email => $name) {
            $mailer->addAddress($email, $name);
        }

        $mailer->isHTML($isHtml);
        $mailer->Subject = $subject;
        $mailer->Body = $body;

        if ($callback !== null) {
            $callback($mailer);
        }

        $result = false;

        try {
            $mailer->send();

            $result = true;
        } catch (\Exception $e) {
            $logger = $this->services->get(
                $this->services->getNameForServiceClass(\PHPMVC\Foundation\Service\LoggerService::class)
            );

            if ($logger !== null) {
                $logger->error($e->getMessage());
            }
        }

        return $result;
    }
}
