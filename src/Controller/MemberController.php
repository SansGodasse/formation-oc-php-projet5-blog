<?php

namespace Controller;


use Application\Exception\AccessException;
use Application\Exception\AppException;
use Application\Exception\HttpException;
use Application\Exception\MemberException;
use Application\Exception\CsrfSecurityException;
use Application\MailSender\MailSender;
use Application\Security\BruteForceProtector;
use Application\Security\CsrfProtector;
use Exception;
use Helper\BlogHelper;
use Helper\MemberHelper;
use Model\Entity\Key;
use Model\Entity\Member;
use Model\Entity\Role;
use Model\Manager\CommentManager;
use Model\Manager\KeyManager;
use Model\Manager\MemberManager;
use Model\Manager\PostManager;
use Model\Manager\RoleManager;
use Twig_Environment;

class MemberController extends Controller
{
    protected $memberManager;
    protected $roleManager;
    protected $postManager;
    protected $commentManager;
    protected $keyManager;
    
    public const POSTS_PER_MEMBER = 3;
    public const COMMENTS_PER_MEMBER = 3;

    public const VIEW_REGISTRATION = 'member/registrationPage.twig';
    public const VIEW_CONNECTION = 'member/connectionPage.twig';
    public const VIEW_WELCOME = 'member/welcomePage.twig';
    public const VIEW_MEMBER_PROFILE = 'member/profilePage.twig';
    public const VIEW_MEMBER_PROFILE_EDITOR = 'member/profileEditor.twig';
    public const VIEW_PASSWORD_RECOVERY = 'member/passwordRecovery.twig';
    public const VIEW_QUIT_PAGE = 'member/quitPage.twig';

    public const AUTHORIZED_ROLES = ['author', 'admin', 'editor', 'moderator'];

    public const KEY_CONNECTED_MEMBER = "connected-member";
    public const KEY_MEMBER = "member";
    public const KEY_WRONG_FIELDS = "wrongFields";
    public const KEY_AVAILABLE_ROLES = "availableRoles";
    public const KEY_QUESTION_SENT = "questionSent";
    public const KEY_MEMBER_POSTS = "memberPosts";
    public const KEY_POSTS_COUNT = "postsCount";
    public const KEY_POSTS_PAGE = "postsPage";
    public const KEY_POSTS_PAGES_COUNT = "postsPagesCount";
    public const KEY_MEMBER_COMMENTS = "memberComments";


    public const MESSAGE_PASSWORD_REQUIREMENTS = "Le mot de passe doit comporter au moins 8 caractères dont une lettre minuscule, une lettre majuscule, un chiffre et un caractère spécial. Bon courage ! ☺";

    public function __construct(
        MemberManager $memberManager,
        RoleManager $roleManager,
        PostManager $postManager,
        CommentManager $commentManager,
        KeyManager $keyManager,
        Twig_Environment $twig
    ) {
        parent::__construct($twig);
        $this->memberManager = $memberManager;
        $this->roleManager = $roleManager;
        $this->postManager = $postManager;
        $this->commentManager = $commentManager;
        $this->keyManager = $keyManager;
    }

    /**
     * Check if the connected member can have access to the admin section
     *
     * @param array|null $authorizedRoles
     * @return bool
     * @throws AccessException
     */
    public static function verifyAccess(?array $authorizedRoles = null): bool
    {
        if (MemberHelper::memberConnected()) {
            if (self::hasAuthorizedRole($authorizedRoles ?? self::AUTHORIZED_ROLES, $_SESSION[self::KEY_CONNECTED_MEMBER]->getRoles())) {
                return true;
            }
            throw new AccessException('Access denied. You lack the proper role.');
        }
        throw new AccessException('Access denied. You are not connected.');
    }

    // Views

    /**
     * Show a welcome page for new members
     *
     * @param string|null $message
     * @param bool $questionSent
     * @throws \Twig_Error_Loader
     * @throws \Twig_Error_Runtime
     * @throws \Twig_Error_Syntax
     */
    public function showWelcomePage(?string $message = "", bool $questionSent = false)
    {
        $this->render(self::VIEW_WELCOME, [
            BlogController::KEY_MESSAGE => $message,
            self::KEY_QUESTION_SENT => $questionSent
        ]);
    }

    /**
     * Show the static profile of a member
     *
     * @param int|null $memberId
     * @param string|null $message
     * @param int $postsPage
     * @param int $commentsPage
     * @throws HttpException
     * @throws \Twig_Error_Loader
     * @throws \Twig_Error_Runtime
     * @throws \Twig_Error_Syntax
     */
    public function showMemberProfile(?int $memberId = null, ?string $message = null, int $postsPage = 1, int $commentsPage = 1)
    {
        if ($memberId !== null && $memberId > 0) {
            $member = $this->memberManager->get($memberId);
        } else {
            $member = $_SESSION[self::KEY_CONNECTED_MEMBER];
        }

        // Posts paging
        $postsCount = $this->postManager->countPostsOfAMember($member->getId(), true);
        $postsPagesCount = ceil($postsCount / self::POSTS_PER_MEMBER);

        if ($postsPage < 1) {
            $postsPage = 1;
        } elseif ($postsPage > $postsPagesCount) {
            $postsPage = $postsPagesCount;
        }
        
        $memberPosts = $this->postManager->getPostsOfAMember($member->getId(), false, true, self::POSTS_PER_MEMBER, ($postsPage - 1) * self::POSTS_PER_MEMBER);

        // Comments paging
        $commentsCount = $this->commentManager->countComments(null, $member->getId(), true, true);
        $commentsPagesCount = ceil($commentsCount / self::COMMENTS_PER_MEMBER);

        if ($commentsPage < 1) {
            $commentsPage = 1;
        } elseif ($commentsPage > $commentsPagesCount) {
            $commentsPage = $commentsPagesCount;
        }

        $memberComments = $this->commentManager->getCommentsOfAMember($member->getId(), true, self::COMMENTS_PER_MEMBER, ($commentsPage - 1) * self::COMMENTS_PER_MEMBER);

        foreach ($memberComments as $memberComment) {
            BlogHelper::convertDatesOfComment($memberComment);
        }

        $this->render(self::VIEW_MEMBER_PROFILE, [
            self::KEY_MEMBER => $member,
            self::KEY_MEMBER_POSTS => $memberPosts,
            self::KEY_POSTS_COUNT => $postsCount,
            self::KEY_POSTS_PAGE => $postsPage,
            self::KEY_POSTS_PAGES_COUNT => $postsPagesCount,
            self::KEY_MEMBER_COMMENTS => $memberComments,
            BlogController::KEY_COMMENTS_COUNT => $commentsCount,
            BlogController::KEY_COMMENTS_PAGE => $commentsPage,
            BlogController::KEY_COMMENTS_PAGES_COUNT => $commentsPagesCount,
            BlogController::KEY_MESSAGE => $message
        ]);
    }

    /**
     * Show the profile editor and launch actions like edit or delete
     *
     * @param null $member
     * @param int|null $keyValue
     * @throws AppException
     * @throws CsrfSecurityException
     * @throws HttpException
     * @throws \ReflectionException
     * @throws \Twig_Error_Loader
     * @throws \Twig_Error_Runtime
     * @throws \Twig_Error_Syntax
     */
    public function profileEditor($member = null, ?int $keyValue = null)
    {
        CsrfProtector::checkCsrf();
        if (isset($_GET[self::KEY_ACTION]) && !empty($_GET[self::KEY_ACTION])) {
            if ($_GET[self::KEY_ACTION] === 'update') {
                $this->updateProfile();
            } elseif ($_GET[self::KEY_ACTION] === 'delete') {
                $this->deleteMember((int) $_POST['id']);
            }
        } else {
            $this->showMemberProfileEditor($member, $keyValue);
        }
    }

    /**
     * Show a page to recover a lost password
     *
     * @param string|null $message
     * @throws \Twig_Error_Loader
     * @throws \Twig_Error_Runtime
     * @throws \Twig_Error_Syntax
     */
    public function showPasswordRecovery(?string $message = null)
    {
        $this->render(self::VIEW_PASSWORD_RECOVERY, [
            BlogController::KEY_MESSAGE => $message
        ]);
    }

    /**
     * Page shown when a member delete his account
     *
     * @throws \Twig_Error_Loader
     * @throws \Twig_Error_Runtime
     * @throws \Twig_Error_Syntax
     */
    public function showQuitPage()
    {
        $this->render(self::VIEW_QUIT_PAGE);
    }

    // Actions

    /**
     * Update the profile of a member
     *
     * @throws AppException
     * @throws HttpException
     * @throws \ReflectionException
     * @throws \Twig_Error_Loader
     * @throws \Twig_Error_Runtime
     * @throws \Twig_Error_Syntax
     */
    public function updateProfile()
    {
        if (
            isset($_POST['name']) &&
            isset($_POST[Member::KEY_EMAIL]) &&
            isset($_POST[Member::KEY_DESCRIPTION])
        ) {
            $modifiedMember = $this->buildMemberFromForm();
            if (
                !empty($_POST[Member::KEY_PASSWORD]) &&
                !MemberHelper::hasStrongPassword($_POST[Member::KEY_PASSWORD])
            ) {
                $this->showMemberProfileEditor($modifiedMember, null, self::MESSAGE_PASSWORD_REQUIREMENTS);
                return false;
            }
            if (isset($_POST['keep-roles'])) {
                $this->memberManager->edit($modifiedMember, false);
            } else {
                $this->memberManager->edit($modifiedMember, true);
            }
            if ($modifiedMember->getId() === $_SESSION[self::KEY_CONNECTED_MEMBER]->getId()) {
                $_SESSION[self::KEY_CONNECTED_MEMBER] = $modifiedMember;
            }
            $this->showMemberProfile($modifiedMember->getId(), "Votre profil a été modifié.");

        } else {
            throw new AppException('$_POST lacks the requested keys to update the member.');
        }
    }

    /**
     * Delete a member
     *
     * @param int $memberId
     * @throws \Twig_Error_Loader
     * @throws \Twig_Error_Runtime
     * @throws \Twig_Error_Syntax
     * @throws HttpException
     */
    public function deleteMember(int $memberId)
    {
        $this->memberManager->delete($memberId);
        if ($_SESSION[self::KEY_CONNECTED_MEMBER]->getId() === $memberId) {
            unset($_SESSION[self::KEY_CONNECTED_MEMBER]);
            $this->render(self::VIEW_QUIT_PAGE);
        } else {
            header('Location: /admin#admin-member-list');
        }
    }

    /**
     * Register a new member
     *
     * @throws \Twig_Error_Loader
     * @throws \Twig_Error_Runtime
     * @throws \Twig_Error_Syntax
     * @throws \Application\Exception\HttpException
     * @throws Exception
     */
    public function register()
    {
        if (MemberHelper::memberConnected()) {
            $this->showRegistrationPage("Déconnectez-vous pour inscrire une autre personne.");
            return false;
        }
        if (
            isset($_POST[Member::KEY_NAME]) &&
            isset($_POST[Member::KEY_EMAIL]) &&
            isset($_POST[Member::KEY_PASSWORD])
        ) {
            $emptyFields = [];
            $message = "";

            if (MemberHelper::checkEmptyRegistrationFields($emptyFields, $message)) {
                if (!filter_var($_POST[Member::KEY_EMAIL], FILTER_VALIDATE_EMAIL)) {
                    $this->showRegistrationPage("L'email est mal écrit.", ["email"]);
                    return false;
                }
                $member = $this->buildMemberFromForm();
                $isNewEmail = $this->memberManager->isNewEmail($member->getEmail());
                $isNewName = $this->memberManager->isNewName($member->getName());
                $hasStrongPassword = MemberHelper::hasStrongPassword($_POST[Member::KEY_PASSWORD]);

                if (
                    !$isNewName ||
                    !$isNewEmail ||
                    !$hasStrongPassword
                ) {
                    $wrongFields = [];
                    $message = "";
                    MemberHelper::setWrongRegistrationFields($wrongFields, $message, $isNewName, $isNewEmail, $hasStrongPassword);
                    $this->showRegistrationPage($message, $wrongFields);
                } else {
                    // Everything is fine, register the member
                    $this->addNewMember($member);
                    $_SESSION[self::KEY_CONNECTED_MEMBER] = $member;
                    $this->showWelcomePage();
                }
            } else {
                $this->showRegistrationPage($message, $emptyFields);
            }
        } else {
            $this->showRegistrationPage();
        }
    }

    /**
     * Connect a member from the connection page
     *
     * @throws \Twig_Error_Loader
     * @throws \Twig_Error_Runtime
     * @throws \Twig_Error_Syntax
     * @throws \Application\Exception\HttpException
     * @throws AppException
     * @throws \ReflectionException
     */
    public function connect()
    {
        if (isset($_POST[Member::KEY_EMAIL]) && isset($_POST[Member::KEY_PASSWORD])) {
            $emptyFields = [];
            $message = "";
            if (!MemberHelper::checkEmptyConnectionFields($emptyFields, $message)) {
                $this->showConnectionPage($message, $emptyFields);

            } else {
                $member = $this->memberManager->getFromEmail($_POST[Member::KEY_EMAIL]);

                if ($member !== null) {
                    // Brute force protection
                    $waitingTime = BruteForceProtector::canConnectAgainIn();
                    if ($waitingTime > 0) {
                        $this->showConnectionPage("Vous vous êtes trompé trop souvent. Attendez un moment pour réfléchir.<br>Temps restant : $waitingTime s");
                        return false;

                    } elseif (password_verify($_POST[Member::KEY_PASSWORD], $member->getPassword())) {
                        $_SESSION[self::KEY_CONNECTED_MEMBER] = $member;
                        BruteForceProtector::resetTheUser();
                        header('Location: /home');
                    }
                }
                $this->showConnectionPage("Erreur dans l'email ou le mot de passe.", [
                    Member::KEY_EMAIL,
                    Member::KEY_PASSWORD
                ]);
            }
        } else {
            $this->showConnectionPage();
        }
    }

    /**
     * Disconnect the member
     */
    public function disconnect()
    {
        unset($_SESSION[self::KEY_CONNECTED_MEMBER]);
        header('Location: /home');
    }

    /**
     * Send an email with a link to reset a password
     *
     * @throws \Application\Exception\HttpException
     * @throws \Twig_Error_Loader
     * @throws \Twig_Error_Runtime
     * @throws \Twig_Error_Syntax
     * @throws AppException
     * @throws \ReflectionException
     * @throws Exception
     */
    public function passwordLost()
    {
        if (isset($_GET[self::KEY_ACTION]) && $_GET[self::KEY_ACTION] = 'send') {
            $email = htmlspecialchars($_POST[Member::KEY_EMAIL]);
            if (!empty($email) && $this->memberManager->emailExists($email)) {
                $memberId = $this->memberManager->getId(null, $email);
                $key = new Key(['value' => random_int(0, 123456789)]);
                $this->keyManager->add($key);
                $link = $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . '/profile-editor?id=' . $memberId . '&key=' . $key->getValue();
                $subject = 'Blog de Nicolas Renvoisé - Mot de passe perdu';
                $content = 'Bonjour, pour réinitialiser votre mot de passe, suivez ce lien : ' . $link;

                if (!MailSender::send($email, $subject, $content)) {
                    $key = $this->keyManager->get(null, $key->getValue());
                    $this->keyManager->delete($key->getId());
                    $this->showPasswordRecovery("L'email n'a pas pu être envoyé. Veuillez réessayer.");
                } else {
                    $this->showConnectionPage('Un email a été envoyé à l\'adresse ' . $email . ' pour vous permettre de réinitialiser votre mot de passe');
                }
            } else {
                $this->showPasswordRecovery('Vous devez entrer un email valide');
            }
        } else {
            $this->showPasswordRecovery('Un mail contenant la marche à suivre va vous être envoyé en remplissant ce formulaire');
        }
    }

    /**
     * Send a mail to the admins to ask for a specific role
     *
     * @param string $role
     * @throws HttpException
     * @throws \Twig_Error_Loader
     * @throws \Twig_Error_Runtime
     * @throws \Twig_Error_Syntax
     */
    public function askRole(string $role)
    {
        $admins = $this->memberManager->getMembersByRole(Member::AUTHOR);
        $contactName = htmlspecialchars($_SESSION[self::KEY_CONNECTED_MEMBER]->getName());
        $subject = htmlspecialchars("Blog de Nicolas Renvoisé : {$contactName} souhaite devenir {$role}");
        $message = htmlspecialchars("{$contactName} souhaite devenir {$role}");
        $from = htmlspecialchars($_SESSION[self::KEY_CONNECTED_MEMBER]->getEmail());

        foreach ($admins as $admin) {
            MailSender::send(
                $admin->getEmail(),
                $subject,
                $message,
                $from
            );
        }

        $this->showWelcomePage("Votre demande a été envoyée et sera traitée dès que possible.", true);
    }

    /**
     * Check if a role is in the authorized roles
     *
     * @param array $rolesToCheck
     * @param array $authorizedRoles
     * @return bool
     */
    public static function hasAuthorizedRole(array $rolesToCheck, array $authorizedRoles)
    {
        foreach ($rolesToCheck as $roleToCheck) {
            if (in_array($roleToCheck, $authorizedRoles)) {
                return true;
            }
        }
        return false;
    }

    // Private

    /**
     * Show the connection page
     *
     * @param string|null $message
     * @param array|null $wrongFields
     * @throws \Twig_Error_Loader
     * @throws \Twig_Error_Runtime
     * @throws \Twig_Error_Syntax
     */
    private function showConnectionPage(?string $message = null, ?array $wrongFields = null)
    {
        $this->render(self::VIEW_CONNECTION, [
            Member::KEY_EMAIL => $_POST[Member::KEY_EMAIL] ?? null,
            self::KEY_WRONG_FIELDS => $wrongFields,
            BlogController::KEY_MESSAGE => $message
        ]);
    }

    /**
     * Show the registration page
     *
     * @param string|null $message
     * @param array|null $wrongFields
     * @throws \Twig_Error_Loader
     * @throws \Twig_Error_Runtime
     * @throws \Twig_Error_Syntax
     */
    private function showRegistrationPage(?string $message = null, ?array $wrongFields = null)
    {
        $this->render(self::VIEW_REGISTRATION, [
            Member::KEY_EMAIL => $_POST[Member::KEY_EMAIL] ?? null,
            Member::KEY_NAME => $_POST[Member::KEY_NAME] ?? null,
            self::KEY_WRONG_FIELDS => $wrongFields,
            BlogController::KEY_MESSAGE => $message
        ]);
    }

    /**
     * Show the profile of a member with the ability to edit it
     *
     * @param Member|null $member
     * @param int|null $keyValue
     * @param string|null $message
     * @throws AppException
     * @throws HttpException
     * @throws \Twig_Error_Loader
     * @throws \Twig_Error_Runtime
     * @throws \Twig_Error_Syntax
     */
    private function showMemberProfileEditor($member = null, ?int $keyValue = null, ?string $message = null)
    {
        $availableRoles = $this->roleManager->getRoleNames();

        if (MemberHelper::memberConnected()) {

            if ($member === null || $member === 0) {
                $member = $_SESSION[self::KEY_CONNECTED_MEMBER];
            } elseif (!($member instanceof Member) && in_array(Member::AUTHOR, $_SESSION[self::KEY_CONNECTED_MEMBER]->getRoles())) {
                $member = $this->memberManager->get((int) $member);
            }

            $this->render(self::VIEW_MEMBER_PROFILE_EDITOR, [
                self::KEY_MEMBER => $member,
                self::KEY_AVAILABLE_ROLES => $availableRoles,
                BlogController::KEY_MESSAGE => $message
            ]);

        } elseif ($keyValue) {
            try {
                $key = $this->keyManager->get(null, $keyValue);
                $this->keyManager->delete($key->getId());
            } catch (HttpException $e) {
                $this->showConnectionPage("La clé demandée n'existe plus. Relancez la procédure de récupération du mot de passe.");
            }
            $member = $this->memberManager->get($member->getId());
            $_SESSION[self::KEY_CONNECTED_MEMBER] = $member;

            $this->render(self::VIEW_MEMBER_PROFILE_EDITOR, [
                self::KEY_MEMBER => $member,
                self::KEY_AVAILABLE_ROLES => $availableRoles,
                BlogController::KEY_MESSAGE => $message
            ]);
        } else {
            throw new AppException('You can not edit a profile if you are not connected.');
        }
    }

    /**
     * Create a Member from a form with $_POST
     *
     * @return Member
     * @throws \Application\Exception\HttpException
     */
    private function buildMemberFromForm(): Member
    {
        $member = new Member();

        $member->setEmail(htmlspecialchars($_POST[Member::KEY_EMAIL]));

        if (isset($_POST[Member::KEY_PASSWORD]) && !empty($_POST[Member::KEY_PASSWORD])) {
            $member->setPassword(password_hash($_POST[Member::KEY_PASSWORD], PASSWORD_DEFAULT));
        }

        $member->setName(htmlspecialchars($_POST['name']));

        if (isset($_POST[Member::KEY_DESCRIPTION])) {
            $member->setDescription(htmlspecialchars($_POST[Member::KEY_DESCRIPTION]));
        }

        if (isset($_POST['id']) && !empty($_POST['id'])) {
            $member->setId((int) $_POST['id']);
        } elseif (MemberHelper::memberConnected()) {
            $member->setId($_SESSION[self::KEY_CONNECTED_MEMBER]->getId());
        }

        if (isset($_POST['roles'])) {
            $roles = [];
            foreach ($_POST['roles'] as $role) {
                if ($this->roleManager->isValid($role)) {
                    $roles[] = $role;
                }
            }

            if (empty($roles)) {
                $roles = [self::KEY_MEMBER];
            }

            $member->setRoles($roles);
        }

        return $member;
    }

    /**
     * Add a new Member
     *
     * @param Member $member
     * @throws Exception
     */
    private function addNewMember(Member $member)
    {
        $member->setRoles([self::KEY_MEMBER]);
        $this->memberManager->add($member);
    }
}
