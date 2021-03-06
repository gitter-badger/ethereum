<?php

namespace Drupal\ethereum_user_connector\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Ethereum\DataType\CallTransaction;
use Ethereum\DataType\EthBlockParam;
use Ethereum\DataType\EthD;
use Ethereum\DataType\EthD20;
use Ethereum\Ethereum;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
* Defines a form to configure maintenance settings for this site.
*/
class AdminForm extends ConfigFormBase implements ContainerInjectionInterface {

  /**
   * The Ethereum JsonRPC client.
   *
   * @var \Ethereum\Ethereum
   */
  protected $client;

  /**
   * Constructs a new AdminForm.
   */
  public function __construct(ConfigFactoryInterface $config_factory, Ethereum $ethereum_client) {
    parent::__construct($config_factory);
    $this->client = $ethereum_client;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('ethereum.client')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormID() {
    return 'ethereum_user_connector_admin';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['ethereum_user_connector.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    // TODO: Type should be Ethereum address, when ethereum-php-lib supports it.
    $config = $this->config('ethereum_user_connector.settings');

    $link = new Url('ethereum.settings');

    $form['html'] = [
      '#markup' => $this->t('<strong>Note:</strong> Only the value for the currently <a href="@link">active Ethereum Node</a> will be validated.', array('@link' => $link->toString())),
    ];

    $form['infura_kovan'] = [
      '#type' => 'textfield',
      '#title' => $this->t("Login Contract Address Ethereum Kovan test network"),
      '#default_value' => $config->get('infura_kovan'),
      '#attributes' => array('disabled' => TRUE),
      '#description' => $this->t('Pre-deployed on Ethereum kovan test network.') . ' <a href="https://kovan.etherscan.io/address/' . $config->get('infura_kovan') . '">' . $config->get('infura_kovan') . '</a>',
    ];

    $form['infura_ropsten'] = [
      '#type' => 'textfield',
      '#title' => $this->t("Login Contract Address Ethereum Ropsten test network"),
      '#default_value' => $config->get('infura_ropsten'),
      '#attributes' => array('disabled' => TRUE),
      '#description' => $this->t('Pre-deployed on Ethereum Ropsten test network (may be very slow).') . ' <a href="https://ropsten.etherscan.io/address/' . $config->get('infura_ropsten') . '">' . $config->get('infura_ropsten') . '</a>',
    ];

    $form['mainnet'] = [
      '#type' => 'textfield',
      '#title' => $this->t("Login Contract Address on Ethereum main network"),
      '#default_value' => $config->get('mainnet'),
//      '#attributes' => array('disabled' => TRUE),
      '#description' => $this->t('Pre-deployed on Ethereum main network. Alpha testing. Not deployed yet!'),
    ];

    $form['custom'] = [
      '#type' => 'textfield',
      '#title' => $this->t("Login Contract Address"),
      '#default_value' => $config->get('custom'),
      '#description' => $this->t('Self deployed login smart contract address.'),
    ];

    $form['info'] = [
      '#markup' => '<br /><h3>Smart contract ABI</h3><p>Currently this is hardcoded. Will integrated with Ethereum <em>Smart Contract module</em></p><pre>
111b72c3 accountCreated(address,bytes32,int256)
3af41dc2 adminDeleteRegistry()
345e3416 adminRetrieveDonations()
49f0c90d adminSetAccountAdministrator(address)
9b6d86d6 adminSetRegistrationDisabled(bool)
06ae9483 contractExists()
f845862f newUser(bytes32)
2573ce27 validateUserByHash(bytes32)</pre>',
    ];


    $form['contract_contractExists_call'] = [
      '#type' => 'textfield',
      '#title' => $this->t("ABI for contractExist call"),
      '#attributes' => array('disabled' => TRUE),
      '#default_value' => $config->get('contract_contractExists_call'),
    ];

    $form['contract_newUser_call'] = [
      '#type' => 'textfield',
      '#title' => $this->t("ABI for register call"),
      '#attributes' => array('disabled' => TRUE),
      '#default_value' => $config->get('contract_newUser_call'),
    ];

    $form['contract_validateUserByHash_call'] = [
      '#type' => 'textfield',
      '#title' => $this->t("ABI for validate call"),
      '#attributes' => array('disabled' => TRUE),
      '#default_value' => $config->get('contract_validateUserByHash_call'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {

    // Trim input for custom.
    $custom_val = trim($form_state->getValue('custom'));
    $form_state->setValue('custom', $custom_val);

    $active_server = $this->configFactory->get('ethereum.settings')->get('current_server');
    $val = $form_state->getValue($active_server);

    try {
      // Validate contract address.
      $signature = '0x' . $this->config('ethereum_user_connector.settings')->get('contract_contractExists_call');
      /**
       * E.g:
       * curl -X POST --data '{"jsonrpc":"2.0","method":"eth_call","params":[{"to":"0xaaaafb8dbb9f5c9d82f085e770f4ed65f3b3107c", "data":"0x06ae9483"},"latest"],"id":1}' localhost:8545
      */
      $message = new CallTransaction(new EthD20($val), NULL, NULL, NULL, NULL, new EthD($signature));
      $result = $this->client->eth_call($message, new EthBlockParam());
      //
      // Debug JsonRPC contract validation call.
      // $eth->debug();
      //
      // Set expected data type.
      // TODO convertTo is depreciated. please use toconvertByAbi($abiType).
      $contract_exists = $result->convertTo('bool')->val();
      if (!$contract_exists) {
        $form_state->setErrorByName('contract_address', $this->t('Unable to verify that contract exists at address: @address'), array('@address' => $val));
      }
    }
    catch (\Exception $exception) {
      $msg = $this->t("Unable find contract in currently active network. Please validate contract address on the network selected in admin/config/ethereum/network.");
      $msg .= 'Error: ' . $exception->getMessage();
      $form_state->setErrorByName($active_server, $msg);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = \Drupal::configFactory()->getEditable('ethereum_user_connector.settings');

    // White listing variables
    $settings = [
      'custom',
      'infura_kovan',
      'infura_ropsten',
      'mainnet',
      'contract_newUser_call',
      'contract_validateUserByHash_call',
      'contract_contractExists_call',
    ];
    $values = $form_state->getValues();
    foreach ($settings as $setting) {
      $config->set($setting, $values[$setting]);
    }
    $config->save();

    parent::submitForm($form, $form_state);
  }

}
