<?php
/*
 * This file is part of mracine/php-routeros-api.
 *
 * (c) Matthieu Racine <matthieu.racine@gmail.com>
 * Issued from collaboration with https://github.com/EvilFreelancer/routeros-api-php
 * Best regards Paul
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace mracine\RouterOS\API;

use mracine\RouterOS\API\Exception\ClientException;
use mracine\Streams\ResourceStream;

use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\Exception\ExceptionInterface as OptionsResolverException;

/**
 * Class Client 
 *
 * Frontend for RouterOS API acces
 * 
 * @author Matthieu Racine <matthieu.racine@gmail.com>
 * @since   0.1.0
 */
class Client
{
    /**
     * @var array $options client options
     */
    protected $options;

    /**
     * @var Connector $conector API communication object
     */

    protected $connector;

    /**
     * Client constructor.
     *
     * @param array $options
     */
    public function __construct(array $options)
    {
        $resolver = new OptionsResolver();
        $this->configureOptions($resolver);
        try {
            $this->options = $resolver->resolve($options);
        }
        catch(OptionsResolverException $e) {
            throw new ClientException($e->getMessage());
        }

        if ($this->options['auto_connect']) {
            $this->connect($this->options['auto_login']);
        }
    }

    public function getState()
    {
        return $this->connector->getState();
    }
    /**
     * Configure constructor's options
     *
     * Options are :
     *  - host: type string. Required. The hostname or IP address of the RouterOS
     *  - login: type string. Required.
     *  - password: type string. Required.
     *  - leacy_login : type boolean. Default true. Use legacy method (pre 6.43). NB: legacy login method is more secure because password is hashed, non legacy method send clear text password.
     *  - ssl: type boolean. Default true. Use encrypted connexion.
     *  - port: type integer. Default is function of ssl value (8729 or 8728, standards RouterOS API ports)
     *  - socket: type array. Optional. Array of socket parameters.
     *    - connection_timeout. type integer. Default to system's configuration. Socket time out when connexting to the socket.
     *    - response_timeout. type integer. Default to system's configuration. Socket timeout when waiting for a response from RouterOS API.
     *  - auto_connect. type boolean. Default true. If true, try to connect when constructing the object.
     *  - auto_login. type boolean. Default true. If true, try to login, on connection success, when constructing the object.
     *  - IknowItsInsecure. type boolean. Default false. Security option : if ssl is not enabled and non legacy login method is used, the login method is not secure. This option have to be set to true to prove you know that what you are doing is NOT secure.
     *
     * @param OptionResolver resolver
     */
    protected function configureOptions(OptionsResolver $resolver)
    {
        $resolver
            ->setRequired('host')
            ->setAllowedTypes('host', ['string'])
            ->setRequired('login')
            ->setAllowedTypes('login', ['string'])
            ->setRequired('password')
            ->setAllowedTypes('password', ['string'])
            ->setDefault('legacy_login', true)
            ->setAllowedTypes('legacy_login', ['boolean'])
            ->setDefault('ssl', true)
            ->setAllowedTypes('ssl', ['boolean'])
            ->setDefault('port', function (Options $options) {
                if (false === $options['ssl']) {
                    return 8728;
                }
                return 8729;
            })
            ->setAllowedTypes('port', ['int'])
            ->setAllowedValues('port', function ($value) {
                if($value<0 || $value>65535) {
                    return false;
                }
                return true;
            })
            ->setDefault('socket', function (OptionsResolver $socketResolver) {
                $socketResolver
                    ->setDefault('connection_timeout', null)
                    ->setAllowedTypes('connection_timeout', ['null', 'int'])
                    ->setDefault('response_timeout', null)
                    ->setAllowedTypes('response_timeout', ['null', 'int'])
                ;
            })
            ->setDefault('auto_connect', true)
            ->setAllowedTypes('auto_connect', ['boolean'])
            ->setDefault('auto_login', true)
            ->setAllowedTypes('auto_login', ['boolean'])
            ->setDefault('IknowItsInsecure', false)
            ->setAllowedTypes('IknowItsInsecure', ['boolean'])
        ;
    } 

    /**
     * Establish tcp connection to RouterOS API
     *
     * @throws ClientException on connexion Error
     * @return Client 
     */
    public function connect(bool $autoLogin=true)
    {
        if ($this->isConnected()) {
            throw new ClientException("Already connected");
        }

        $this->connector = new Connector(new ResourceStream($this->createSocket()));
        if($autoLogin) {
            $this->login();
        }

        return $this;
    }

    public function isConnected()
    {
        return (!is_null($this->connector)) && $this->connector->isConnected();
    }

    protected function createSocket()
    {
        $proto = $this->options['ssl'] ? 'ssl://' : 'tcp://';
        $context = stream_context_create([
            'ssl' => [
                'ciphers'          => 'ADH:ALL',
                'verify_peer'      => false,
                'verify_peer_name' => false
            ]
        ]);

        $socket_err_num = 0;
        $socket_err_str = '';
        $socket = @stream_socket_client(
            $proto . $this->options['host'] . ':' . $this->options['port'],
            $socket_err_num,
            $socket_err_str,
            $this->options['socket']['connection_timeout'] === null ? ini_get("default_socket_timeout") : $this->options['socket']['connection_timeout'],
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (false === $socket) {
            throw new ClientException(sprintf("Connection error (%d) : %s", $socket_err_num, $socket_err_str));
        }

        if (null !== $this->options['socket']['response_timeout']) {
            stream_set_timeout($socket, $this->options['socket']['response_timeout']);
        }
        return $socket;
    }

    /**
     * Login to routerOS
     */
    public function login()
    {
        if (is_null($this->connector)) {
            $this->connect(false);
        }

        // Legacy login method works on non legacy firmware (>6.43) 
        // Legacy Method is more secure because it does not send clear text password
        // Non legacy method should only be allowed on encrypted (SSL) connexions

        if (!$this->options['legacy_login']) {
            if (!$this->options['ssl'] && !$this->options['IknowItsInsecure']) {
                throw new ClientException("Non legacy login should only be used on SSL connections. Use IknowItsInsecure option to force");
            }
            $this->connector->nonLegacyLogin($this->options['login'], $this->options['password'], true);
        } else {
            $this->connector->legacyLogin($this->options['login'], $this->options['password']);
        }
    }

    public function quit()
    {
        return $this->connector->quit();
    }

    // public function send($wordOrQuery, bool $parseResponse=true)
    public function send(string $word, array $attributes = [], bool $parseResponse=true)
    {
        $query = null;

        $this->connector->writeWord($word);
        foreach($attributes as $attribute=>$value)
        {
            $this->connector->writeWord(sprintf('=%s=%s', $attribute, $value));
        }
        
        $this->connector->writeEnd();
        return $this->connector->getSentence($parseResponse);
    }

    public function query(string $word, array $queries = [], bool $parseResponse=true)
    {
        $query = null;

        if (!preg_match('/\/print$/', $word))
        {
            throw new ClientException(sprintf("Queries must end with /print statement, %s received", $word));
            
        }
        $this->connector->writeWord($word);
        foreach($queries as $query)
        {
            $this->connector->writeWord($query);
        }
        
        $this->connector->writeEnd();
        return $this->connector->getSentence($parseResponse);
    }

}
