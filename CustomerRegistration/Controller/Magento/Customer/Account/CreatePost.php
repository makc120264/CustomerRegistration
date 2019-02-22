<?php

namespace Medbuy\CustomerRegistration\Controller\Magento\Customer\Account;

use Magento\Customer\Api\AccountManagementInterface;

class CreatePost extends \Magento\Customer\Controller\Account\CreatePost {

    protected $customerGroup;

    public function execute() {

        $params = $this->getRequest()->getParams();

        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $accountRedirect = $objectManager->create("\Magento\Customer\Model\Account\Redirect");

        if ($params['check_valid']) {

            $this->customerGroup = $objectManager->create("\Magento\Customer\Model\ResourceModel\Group\Collection");

            /** @var \Magento\Framework\Controller\Result\Redirect $resultRedirect */
            $resultRedirect = $this->resultRedirectFactory->create();
            if ($this->session->isLoggedIn() || !$this->registration->isAllowed()) {
                $resultRedirect->setPath('*/*/');
                return $resultRedirect;
            }

            if (!$this->getRequest()->isPost()) {
                $url = $this->urlModel->getUrl('*/*/create', ['_secure' => true]);
                $resultRedirect->setUrl($this->_redirect->error($url));
                return $resultRedirect;
            }

            $this->session->regenerateId();

            try {

                if (empty($params['street'])) {
                    $params['street'] = array('unknown street');
                } else {
                    $params['street'] = array('ул.' . $params['street'] . ' д.' . $params['building'] . '-' . $params['office']);
                    $this->_request->setParam('street', $params['street']);
                }

                $address = $this->extractAddress();
                $customer = $this->customerExtractor->extract('customer_account_create', $this->_request);

                $address->setStreet($params['street']);

                if (empty($params['firstname'])) {
                    $address->setFirstname('unknown');
                    $customer->setFirstname('unknown');
                } else {
                    $address->setFirstname($params['firstname']);
                    $customer->setFirstname($params['firstname']);
                }
                if (empty($params['lastname'])) {
                    $address->setLastname('unknown');
                    $customer->setLastname('unknown');
                } else {
                    $address->setLastname($params['lastname']);
                    $customer->setLastname($params['lastname']);
                }

//            if (empty($params['street'])) {
//                $address->setStreet(array('unknown street'));
//            } else {
//                $address->setStreet($params['street']);
//            }

                if (empty($params['city'])) {
                    $address->setCity('unknown city');
                } else {
                    $address->setCity($params['city']);
                }
                if (empty($params['phone'])) {
                    $address->setTelephone('+000000000000');
                } else {
                    $address->setTelephone($params['phone']);
                }
                if (empty($params['postcode'])) {
                    $address->setPostcode('00000');
                } else {
                    $address->setPostcode($params['postcode']);
                }

                $customAttributes = $customer->getCustomAttributes();
                foreach ($customAttributes as $customerAttribute) {
                    if ($customerAttribute->getAttributeCode() == 'mobile_phone') {
                        if (empty($params['mobile_phone'])) {
                            $customer->setCustomAttribute('mobile_phone', '+000000000000');
                        } else {
                            $customer->setCustomAttribute('mobile_phone', $params['mobile_phone']);
                        }
                    } else if ($customerAttribute->getAttributeCode() == 'verified') {
                        $customer->setCustomAttribute('verified', '0');
                    } else {
                        if (empty($params[$customerAttribute->getAttributeCode()])) {
                            $customer->setCustomAttribute($customerAttribute->getAttributeCode(), 'unknown');
                        } else {
                            $customer->setCustomAttribute($customerAttribute->getAttributeCode(), $params[$customerAttribute->getAttributeCode()]);
                        }
                    }
                }
                // set groupId
                $customerGroups = $this->customerGroup->toOptionArray();
                $notLoggedIn = array_shift($customerGroups);
                foreach ($customerGroups as $customerGroup) {
                    $customerGroupsId[] = $customerGroup['value'];
                }
                $accType = $this->getRequest()->getParam('acc_type');
                if (in_array($accType, $customerGroupsId)) {
                    $customer->setGroupId($accType);
                } else {
                    $customer->setGroupId($notLoggedIn['value']);
                }

                $addresses = $address === null ? [] : [$address];
                $customer->setAddresses($addresses);

                $password = $this->getRequest()->getParam('password');
                $confirmation = $this->getRequest()->getParam('password_confirmation');
                $redirectUrl = $this->session->getBeforeAuthUrl();

                $this->checkPasswordConfirmation($password, $confirmation);

                $customer = $this->accountManagement->createAccount($customer, $password, $redirectUrl);
                
                $emailHelper = $objectManager->get(\Medbuy\Customer\Helper\Email::class);
                $emailHelper->sendAdminEmail($customer);
                

                if ($this->getRequest()->getParam('is_subscribed', false)) {
                    $this->subscriberFactory->create()->subscribeCustomerById($customer->getId());
                }

                $this->_eventManager->dispatch('customer_register_success', ['account_controller' => $this, 'customer' => $customer]);

                $confirmationStatus = $this->accountManagement->getConfirmationStatus($customer->getId());
                
                if ($confirmationStatus === AccountManagementInterface::ACCOUNT_CONFIRMATION_REQUIRED) {
                    $email = $this->customerUrl->getEmailConfirmationUrl($customer->getEmail());
                    // @codingStandardsIgnoreStart
                    $this->messageManager->addSuccess(
                            __(
                                    'You must confirm your account. Please check your email for the confirmation link or <a href="%1">click here</a> for a new link.', $email
                            )
                    );
                    // @codingStandardsIgnoreEnd
                    $url = $this->urlModel->getUrl('*/*/index', ['_secure' => true]);
                    $resultRedirect->setUrl($this->_redirect->success($url));
                } else {
                    $this->session->setCustomerDataAsLoggedIn($customer);
                    $this->messageManager->addSuccess($this->getSuccessMessage());
                    $requestedRedirect = $accountRedirect->getRedirectCookie();
                    if (!$this->scopeConfig->getValue('customer/startup/redirect_dashboard') && $requestedRedirect) {
                        $resultRedirect->setUrl($this->_redirect->success($requestedRedirect));
                        $accountRedirect->clearRedirectCookie();
                        return $resultRedirect;
                    }
                    $resultRedirect = $accountRedirect->getRedirect();
                }

                return $resultRedirect;
            } catch (StateException $e) {
                $url = $this->urlModel->getUrl('customer/account/forgotpassword');
                // @codingStandardsIgnoreStart
                $message = __(
                        'There is already an account with this email address. If you are sure that it is your email address, <a href="%1">click here</a> to get your password and access your account.', $url
                );
                // @codingStandardsIgnoreEnd
                $this->messageManager->addError($message);
            } catch (InputException $e) {
                $this->messageManager->addError($this->escaper->escapeHtml($e->getMessage()));
                foreach ($e->getErrors() as $error) {
                    $this->messageManager->addError($this->escaper->escapeHtml($error->getMessage()));
                }
            } catch (LocalizedException $e) {
                $this->messageManager->addError($this->escaper->escapeHtml($e->getMessage()));
            } catch (\Exception $e) {
                $this->messageManager->addException($e, $e->getMessage());
            }

            $this->session->setCustomerFormData($this->getRequest()->getPostValue());
            $defaultUrl = $this->urlModel->getUrl('*/*/create', ['_secure' => true]);
            $resultRedirect->setUrl($this->_redirect->error($defaultUrl));
        } else {
            $resultRedirect = $accountRedirect->getRedirect();
            $defaultUrl = $this->urlModel->getUrl('*/*/create', ['_secure' => true]);
            $resultRedirect->setUrl($this->_redirect->error($defaultUrl));
        }

        return $resultRedirect;
    }

}
