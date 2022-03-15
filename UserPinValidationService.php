<?php

namespace KnygosLt\Modules\Cart\Service;

use KnygosLt\Modules\Cart\Model\UserPinValidationModel;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\OptionsResolver\OptionsResolver;

class UserPinValidationService
{
    private const SEPARATOR = '#';

    public $email;
    public $pin;

    /** @var UserPinValidationModel */
    protected $userPinValidationModel;

    /** @var MailerInterface */
    private $mailer;
    /**
     * @var array
     */
    private $options;
    private $hash_salt;

    public function __construct(
        MailerInterface $mailer,
        UserPinValidationModel $userPinValidationModel,
        array $options = []
    ) {
        $this->mailer = $mailer;
        $this->userPinValidationModel = $userPinValidationModel;

        $resolver = new OptionsResolver();
        $this->configureOptions($resolver);
        $this->options = $resolver->resolve($options);
        $this->hash_salt = $this->options['secret_pin_salt'];
    }

    public function generateNewPin(): string
    {
        $data = $this->fillDefaultData();
        $pin = $this->generateRandomPin();

        $data['email'] = $this->email;
        $data['pin'] = $pin;
        $data['pin_hash'] = $this->getHash($pin);
        $data['full_hash'] = $this->makeFullHash($this->email, $pin);
        $this->userPinValidationModel->insertNewPin($data);

        return $pin;
    }

    public function deletePin($email)
    {
        $data['email'] = $email;
        $this->userPinValidationModel->deletePin($data);
    }

    public function updatePin($email): string
    {
        $data['email'] = $email;
        $data = $this->userPinValidationModel->getUserByEmail($data);
        $pin = $this->generateRandomPin();

        $data['pin'] = $pin;
        $data['pin_hash'] = $this->getHash($pin);
        $data['full_hash'] = $this->makeFullHash($email, $pin);
        $this->userPinValidationModel->updatePin($data);

        return $pin;
    }

    public function checkIfEmailExists(): bool
    {
        $data['email'] = $this->email;

        return $this->userPinValidationModel->checkIfEmailExists($data);
    }

    public function checkIfEmailWithPinExists($email, $pin): bool
    {
        $data['email'] = $email;
        $data['pin'] = $pin;

        return $this->userPinValidationModel->getUserByEmailAndPin($data);
    }

    public function checkIfEmailWithPinHashExists($email, $pin_hash): bool
    {
        $data['email'] = $email;
        $data['pin'] = $pin_hash;

        return $this->userPinValidationModel->getUserByEmailAndPinHash($data);
    }

    public function getUserByFullHash($full_hash)
    {
        $data['full_hash'] = $full_hash;

        return $this->userPinValidationModel->getUserByFullHash($data);
    }

    public function createUser($email)
    {
        return $this->email = $email;
    }

    public function setEmail(string $value)
    {
        $this->email = $value;
    }

    public function setPin(string $value)
    {
        $this->pin = $value;
    }

    public function getHash(string $pin): string
    {
        return crypt($pin, $this->hash_salt);
    }

    public function sendEmailWithPin($email_address, $pin)
    {
        $email = new TemplatedEmail();
        $email->htmlTemplate('email/pay-without-registration-pin.html.twig');
        $email->to(new Address($email_address));
        $email->from(new Address('pagalba@knygos.lt', 'Knygos.lt'));
        $email->subject('Jūsų patvirtinimo kodas: '.$pin);
        $email->context(
            [
                'pin' => $pin,
                'confirmation_link' => $this->makeConfirmationToken($email_address, $pin),
                'addr' => $this->getHash($email_address),
            ]
        );

        $this->mailer->send($email);
    }

    public function makeConfirmationToken($email, $pin): string
    {
        return $this->makeFullHash($email, $pin);
    }

    private function makeFullHash($email, $pin): string
    {
        return $this->getHash($email.self::SEPARATOR.$pin);
    }

    private function generateRandomPin(): string
    {
        return random_int(100000, 999999);
    }

    private function fillDefaultData(): array
    {
        $data = [];
        $data['email'] = '';
        $data['pin'] = '';
        $data['pin_hash'] = '';
        $data['full_hash'] = '';
        $data['attempts'] = '';
        $data['last_attempt'] = '';
        $data['blocked'] = '';
        $data['blocked_on'] = '';
        $data['created'] = '';

        return $data;
    }

    private function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(
            ['secret_pin_salt' => 'ABCDE01^23$456789X@YZ']
        );
    }
}
