<?php

namespace KnygosLt\Modules\Cart\Controller;

use KnygosLt\Modules\Cart\Service\CartBuilder;
use KnygosLt\Modules\Cart\Service\CartIdentifierService;
use KnygosLt\Modules\Cart\Service\CartService;
use KnygosLt\Modules\Cart\Service\UserPinValidationService;
use KnygosLt\Modules\Users\Error\DuplicateEmailException;
use KnygosLt\Modules\Users\Form\EmailPinType;
use KnygosLt\Modules\Users\Security\Core\User\KnygosLtUser;
use KnygosLt\Modules\Users\Service\AuthenticatorService;
use KnygosLt\Modules\Users\Service\Users;
use KnygosLt\Transitional\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class UserPinValidationController extends AbstractController
{
    public function emailInputPin(Request $req): Response
    {
        $user = $this->getUser();
        if ($user instanceof KnygosLtUser && $user->getDetails()['active'] && $user->getDetails(
            )['client_without_registration']) {
            return $this->smartRedirectIfAlreadyRegistered($req, $user->getDetails()['email']);
        }

        $email = '';
        if ($req->request->has('user_login')) {
            $email = trim($req->request->get('user_login'));
        }

        $form = $this->createForm(
            EmailPinType::class,
            ['email' => $email]
        );

        $form->handleRequest($req);
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $pin = trim($data['pin']);
            $email = trim($data['user_login']);
            if ('' == $email) {
                return $this->redirectToRoute('cart.users.login');
            }

            if ($form->get('submit')->isClicked()) {
                if ($this->pinValidationService()->checkIfEmailWithPinExists($email, $pin)) {
                    $this->pinValidationService()->deletePin($email);

                    $redirectUrl = $this->registerAndLoginUser($req, $email, $pin);

                    return $this->render(
                        'cart/partial/pay_without_registration_success.html.twig',
                        [
                            'user_email' => $email,
                            'form' => $form->createView(),
                            'redirectUrl' => $redirectUrl,
                            'cart' => $this->getCart($req),
                        ]
                    );
                }
                $this->addFlash('danger', 'Patvirtinimo kodas neteisingas');
            }

            if ($form->get('repeat')->isClicked()) {
                // kodo pergeneravimas/siuntimo pakartojimas
                $pin = $this->pinValidationService()->updatePin($email);
                $this->pinValidationService()->sendEmailWithPin($email, $pin);
            }
        } else {
            if ('' == $email) {
                return $this->redirectToRoute('cart.users.login');
            }

            $user = $this->users()->getUserByEmail($email);
            if ($user) {
                return $this->redirectToRoute('user.password.remind');
            }

            $this->pinValidationService()->createUser($email);
            if (!$this->pinValidationService()->checkIfEmailExists()) {
                $pin = $this->pinValidationService()->generateNewPin();
                $this->pinValidationService()->sendEmailWithPin($this->pinValidationService()->email, $pin);
            }
        }

        return $this->render(
            'cart/partial/pay_without_registration.html.twig',
            [
                'user_email' => $email,
                'form' => $form->createView(),
                'cart' => $this->getCart($req),
            ]
        );
    }

    public function emailPinConfirmLink(Request $req): Response
    {
        $token = $req->query->get('ut');
        $user = $this->pinValidationService()->getUserByFullHash($token);
        if ($user) {
            $email = $user['email'];
            $pin = $user['pin'];

            $this->pinValidationService()->deletePin($email);
            $redirectUrl = $this->registerAndLoginUser($req, $email, $pin);

            return $this->render(
                'cart/partial/pay_without_registration_success.html.twig',
                [
                    'user_email' => $email,
                    'redirectUrl' => $redirectUrl,
                    'cart' => $this->getCart($req),
                ]
            );
        }

        // galbut jau patvirtintas
        $emailHash = $req->query->get('e');
        if ($emailHash) {
            $users = $this->users()->getUnregisteredUserByEmailAndFullHash($emailHash, $token);
            if ($users) {
                $realEmail = $users['email'];
                $redirectUrl = $this->loginExistedUser($req, $realEmail);

                return $this->render(
                    'cart/partial/pay_without_registration_success.html.twig',
                    [
                        'user_email' => $realEmail,
                        'redirectUrl' => $redirectUrl,
                        'cart' => $this->getCart($req),
                    ]
                );
            }
            // jei niekur neradom, redirektinam i login
            return new RedirectResponse($this->generateUrl('login'));
        }

        return new Response('Neteisingas patvirtinimo kodas', Response::HTTP_BAD_REQUEST);
    }

    public static function getSubscribedServices()
    {
        return array_merge(
            parent::getSubscribedServices(),
            [
                'userPin.validation.service' => UserPinValidationService::class,
                'users.service.users' => Users::class,
                'users.service.authenticator' => AuthenticatorService::class,
                'cart.service.user' => CartIdentifierService::class,
                'cart.service.cart' => CartService::class,
                'cart.builder' => CartBuilder::class,
            ]
        );
    }

    private function getCart(Request $req)
    {
        $cartId = $this->userCart()->getCartId($req);
        $cart = null;
        if (!empty($cartId)) {
            $cart = $this->cartBuilder()->get_cart_data($cartId);
        }

        return $cart;
    }

    private function smartRedirectIfAlreadyRegistered(Request $req, $email): Response
    {
        $redirectUrl = $this->generateUrl('cart.users.login');
        $cartId = $this->userCart()->getCartId($req);
        if (!empty($cartId)) {
            $cartItems = $this->cartService()->getCartItemsFull($cartId);
            if (!empty($cartItems)) {
                $redirectUrl = $this->generateUrl('cart.shipment');
            } else {
                $redirectUrl = $this->generateUrl('cart.view.cart');
            }
        }

        return $this->render(
            'cart/partial/pay_without_registration_success.html.twig',
            [
                'user_email' => $email,
                'redirectUrl' => $redirectUrl,
                'cart' => $this->getCart($req),
            ]
        );
    }

    private function loginExistedUser(Request $req, $email): string
    {
        $redirectUrl = $this->generateUrl('cart.users.login');
        $this->authenticator()->loginWithoutPassword($req, $email);

        $cartId = $this->userCart()->getCartId($req);
        if (!empty($cartId)) {
            $cartItems = $this->cartService()->getCartItemsFull($cartId);
            if (!empty($cartItems)) {
                $redirectUrl = $this->generateUrl('cart.shipment');
            } else {
                $redirectUrl = $this->generateUrl('cart.view.cart');
            }
        }

        return $redirectUrl;
    }

    private function registerAndLoginUser(Request $req, $email, $pin): string
    {
        $redirectUrl = $this->generateUrl('cart.users.login');

        try {
            $userId = $this->users()->addUser(
                $email,
                [
                    'active' => true,
                    'client_without_registration' => true,
                    'country' => 'LT',
                    'email_pin_hash' => $this->pinValidationService()->makeConfirmationToken($email, $pin),
                    'email_hash' => $this->pinValidationService()->getHash($email),
                ]
            );
            $this->authenticator()->setPasword($userId, $pin);
            $this->authenticator()->loginWithoutPassword($req, $email);

            $cartId = $this->userCart()->getCartId($req);
            if (!empty($cartId)) {
                $cartItems = $this->cartService()->getCartItemsFull($cartId);
                if (!empty($cartItems)) {
                    $redirectUrl = $this->generateUrl('cart.shipment');
                } else {
                    $redirectUrl = $this->generateUrl('cart.view.cart');
                }
            }
        } catch (DuplicateEmailException $e) {
            $this->addFlash('danger', 'Toks el. pašto adresas jau egzistuoja');
        }

        return $redirectUrl;
    }

    private function userCart(): CartIdentifierService
    {
        return $this->container->get('cart.service.user');
    }

    private function authenticator(): AuthenticatorService
    {
        return $this->get('users.service.authenticator');
    }

    private function pinValidationService(): UserPinValidationService
    {
        return $this->container->get('userPin.validation.service');
    }

    private function users(): Users
    {
        return $this->container->get('users.service.users');
    }

    private function cartService(): CartService
    {
        return $this->container->get('cart.service.cart');
    }

    private function cartBuilder(): CartBuilder
    {
        return $this->container->get('cart.builder');
    }
}
