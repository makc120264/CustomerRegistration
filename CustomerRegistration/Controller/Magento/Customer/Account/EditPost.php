<?php

namespace Medbuy\CustomerRegistration\Controller\Magento\Customer\Account;

use Magento\Framework\App\ObjectManager;
use Magento\Customer\Model\EmailNotificationInterface;

class EditPost extends \Magento\Customer\Controller\Account\EditPost {

    private $customerMapper;
    private $emailNotification;

    public function execute() {
        /** @var \Magento\Framework\Controller\Result\Redirect $resultRedirect */
        $resultRedirect = $this->resultRedirectFactory->create();
        $validFormKey = $this->formKeyValidator->validate($this->getRequest());

        if ($validFormKey && $this->getRequest()->isPost()) {
            $currentCustomerDataObject = $this->getCustomerDataObject($this->session->getCustomerId());
            $customerCandidateDataObject = $this->populateNewCustomerDataObject($this->_request, $currentCustomerDataObject);

//            if(empty($customerCandidateDataObject->getCustomAttribute('company_website')->getValue())){
//                $customerCandidateDataObject->setCustomAttribute('company_website', $currentCustomerDataObject->getCustomAttribute('company_website')->getValue());
//            }
            
            try {

                // whether a customer enabled change email option
                $this->processChangeEmailRequest($currentCustomerDataObject);

                // whether a customer enabled change password option
                $isPasswordChanged = $this->changeCustomerPassword($currentCustomerDataObject->getEmail());
                $postValue = $this->getRequest()->getPostValue();                

                if($postValue['form'] == 'contact-info'){
                    $anchor = '#customer_edit';
                }
                if ($postValue['form'] == 'company-info') {
                    $anchor = '#company_details';
                    $customerCandidateDataObject->setFirstname($currentCustomerDataObject->getFirstname());
                    $customerCandidateDataObject->setLastname($currentCustomerDataObject->getLastname());
                    $customerCandidateDataObject->setMiddlename($currentCustomerDataObject->getMiddlename());

                    $customAttributes = $currentCustomerDataObject->getCustomAttributes();               
                    foreach ($customAttributes as $attribute) {
                        if (array_key_exists($attribute->getAttributeCode(), $postValue)) {
                            $attrValue = $postValue[$attribute->getAttributeCode()];
                        } else {
                            $attrValue = $attribute->getValue();
                        }                        
                        $customerCandidateDataObject->setCustomAttribute($attribute->getAttributeCode(), $attrValue);
                    }
                    $customerCandidateDataObject->setDob($currentCustomerDataObject->getDob());
                    $customerCandidateDataObject->setGender($currentCustomerDataObject->getGender());

                    $customerCandidateDataObject = $this->setOrigAddresses($postValue, $customerCandidateDataObject);
                }

                if (!empty($postValue['telephone'])) {
                    $customerCandidateDataObject = $this->setTelephone($postValue['telephone'], $customerCandidateDataObject);
                }

                $this->customerRepository->save($customerCandidateDataObject);

                $this->getEmailNotification()->credentialsChanged($customerCandidateDataObject, $currentCustomerDataObject->getEmail(), $isPasswordChanged);
                $this->dispatchSuccessEvent($customerCandidateDataObject);
                $this->messageManager->addSuccess(__('You saved the account information.'));
                
                $redirectUrl = rtrim($this->_url->getUrl('customer/account/' . $anchor), '/');

                return $resultRedirect->setPath($redirectUrl);
                
            } catch (InvalidEmailOrPasswordException $e) {
                $this->messageManager->addError($e->getMessage());
            } catch (UserLockedException $e) {
                $message = __(
                        'You did not sign in correctly or your account is temporarily disabled.'
                );
                $this->session->logout();
                $this->session->start();
                $this->messageManager->addError($message);
                return $resultRedirect->setPath('customer/account/login');
            } catch (InputException $e) {
                $this->messageManager->addError($e->getMessage());
                foreach ($e->getErrors() as $error) {

                    $this->messageManager->addError($error->getMessage());
                }
            } catch (\Magento\Framework\Exception\LocalizedException $e) {
                $this->messageManager->addError($e->getMessage());
            } catch (\Exception $e) {
//                $this->messageManager->addException($e, __('We can\'t save the customer.'));
                $this->messageManager->addException($e, $e->getMessage());
            }

            $this->session->setCustomerFormData($this->getRequest()->getPostValue());
        }

        return $resultRedirect->setPath('customer/account/');
    }

    private function setOrigAddresses($postValue, $customerCandidateDataObject) {
        $postValue['street'] = $postValue['street'] . ' д.' . $postValue['building'] . '-' . $postValue['office'];

        $origAddresses = $customerCandidateDataObject->getAddresses();

        $origAddresses['0']->setCountryId($postValue['company_country']);
        $origAddresses['0']->setCity($postValue['city']);
        $origAddresses['0']->setStreet(array($postValue['street']));
        $origAddresses['0']->setPostcode($postValue['post_code']);

        $customerCandidateDataObject->setAddresses($origAddresses);

        return $customerCandidateDataObject;
    }

    private function setTelephone($telephone, $customerCandidateDataObject) {
        try {
            $origAddresses = $customerCandidateDataObject->getAddresses();
        } catch (Exception $e) {
            $this->messageManager->addError($e->getMessage());
        }
        $origAddresses['0']->setTelephone($telephone);
        $customerCandidateDataObject->setAddresses($origAddresses);

        return $customerCandidateDataObject;
    }

    private function getCustomerDataObject($customerId) {
        return $this->customerRepository->getById($customerId);
    }

    private function populateNewCustomerDataObject(
    \Magento\Framework\App\RequestInterface $inputData, \Magento\Customer\Api\Data\CustomerInterface $currentCustomerData
    ) {
        $attributeValues = $this->getCustomerMapper()->toFlatArray($currentCustomerData);
        $customerDto = $this->customerExtractor->extract(
                self::FORM_DATA_EXTRACTOR_CODE, $inputData, $attributeValues
        );
        $customerDto->setId($currentCustomerData->getId());
        if (!$customerDto->getAddresses()) {
            $customerDto->setAddresses($currentCustomerData->getAddresses());
        }
        if (!$inputData->getParam('change_email')) {
            $customerDto->setEmail($currentCustomerData->getEmail());
        }

        return $customerDto;
    }

    private function getCustomerMapper() {
        if ($this->customerMapper === null) {
            $this->customerMapper = ObjectManager::getInstance()->get(\Magento\Customer\Model\Customer\Mapper::class);
        }
        return $this->customerMapper;
    }

    private function processChangeEmailRequest(\Magento\Customer\Api\Data\CustomerInterface $currentCustomerDataObject) {
        if ($this->getRequest()->getParam('change_email')) {
            // authenticate user for changing email
            try {
                $this->getAuthentication()->authenticate(
                        $currentCustomerDataObject->getId(), $this->getRequest()->getPost('current_password')
                );
            } catch (InvalidEmailOrPasswordException $e) {
                throw new InvalidEmailOrPasswordException(__('The password doesn\'t match this account.'));
            }
        }
    }

    private function getEmailNotification() {
        if (!($this->emailNotification instanceof EmailNotificationInterface)) {
            return ObjectManager::getInstance()->get(
                            EmailNotificationInterface::class
            );
        } else {
            return $this->emailNotification;
        }
    }

    private function dispatchSuccessEvent(\Magento\Customer\Api\Data\CustomerInterface $customerCandidateDataObject) {
        $this->_eventManager->dispatch(
                'customer_account_edited', ['email' => $customerCandidateDataObject->getEmail()]
        );
    }

}
