<?php
namespace ProcessMaker\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Exception;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Hash;
use Illuminate\Encryption\Encrypter;

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Helper\TableCell;

use ProcessMaker\Model\User;

/**
 * Install command handles installing a fresh copy of ProcessMaker BPM.
 * If a .env file is found in the base_path(), then we will refuse to install.
 * Note: This is destructive to your database if you point to an existing database with tables.
 */
class Install extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bpm:install';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install and configure ProcessMaker BPM';

    /**
     * The values for our .env to populate
     * 
     * $var array
     */
    private $env;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        // Our initial .env values
        $this->env = [
            'APP_DEBUG' => 'FALSE',
            'APP_NAME' => 'ProcessMaker',
            'APP_ENV' => 'production',
            'APP_KEY' => 'base64:'.base64_encode(Encrypter::generateKey($this->laravel['config']['app.cipher']))
        ];

    }

    /**
     * Installs a fresh copy of ProcessMaker BPM
     *
     * @return mixed If the command succeeds, true
     */
    public function handle()
    {
        // Configure the filesystem to be local
        config(['filesystems.disks.install' => [
            'driver' => 'local',
            'root' => base_path()
        ]]);

        $this->info("<fg=cyan;bold>" . __("ProcessMaker Installer") . "</>");

        // Determine if .env file exists or not
        // if exists, bail out with an error
        // If file does not exist, begin to generate it
        if(Storage::disk('install')->exists('.env')) {
            $this->error(__("A .env file already exists"));
            $this->error(__("Remove the .env file to perform a new installation"));
            return 255;
        }
        $this->info(__("This application installs a new version of ProcessMaker."));
        $this->info(__("You must have your database credentials available in order to continue."));
        $this->confirm(__("Are you ready to begin?"));
        $this->checkDependencies();
        do {
        $this->fetchDatabaseCredentials();
        } while(!$this->testDatabaseConnection());
        // Ask for URL and validate
        $invalid = false;
        do {
            if($invalid) {
                $this->error(__("The url you provided was invalid. Please provide the scheme, host and path and have no trailing slashes."));
            }
            $this->env['APP_URL'] = $this->ask(__('What is the url of this ProcessMaker Installation? (Ex: https://pm.example.com, no trailing slash)'));
        } while($invalid = (!filter_var($this->env['APP_URL'], 
                                        FILTER_VALIDATE_URL, 
                                        FILTER_FLAG_SCHEME_REQUIRED | 
                                        FILTER_FLAG_HOST_REQUIRED) 
                    || ($this->env['APP_URL'][strlen($this->env['APP_URL']) - 1] == '/'))
        );

        // Set it as our url in our config
        config(['app.url' => $this->env['APP_URL']]);

        $this->info(__("Installing ProcessMaker Database, OAuth SSL Keys and configuration file"));

        // Create database
        // Now install migrations
        $this->call('migrate:fresh', ['--seed' => true]);

        // Generate the required oauth private/public keys
        $privateKey = openssl_pkey_new();
        // Generate a CSR then sign it so we have a cert to extract public from
        $csr = openssl_csr_new([], $privateKey);
        $cert = openssl_csr_sign($csr, null, $privateKey, 365);
        openssl_x509_export($cert, $signedCert);
        $publicKey = openssl_pkey_get_public($signedCert);

        openssl_pkey_export($privateKey, $privateKeyString);
        $publicKeyData = openssl_pkey_get_details($publicKey);
        $publicKeyString = $publicKeyData['key'];

        // Now write the keys out to our key filesystem
        Storage::disk('keys')->put('private.key', $privateKeyString);
        Storage::disk('keys')->put('public.key', $publicKeyString);
        $this->info(__("Finished creating public/private oauth2 ssl keys"));

        // Now generate the .env file
        $contents = '';
        // Build out the file contents for our .env file
        foreach($this->env as $key => $value) {
            $contents .= $key . "=" . $value . "\n";
        }
        // Now store it
        Storage::disk('install')->put('.env', $contents);

        $this->info(__("ProcessMaker installation is complete. Please visit the url in your browser to continue."));
        return true;
    }


    /**
     * The following checks for required extensions needed by ProcessMaker
     */
    private function checkDependencies()
    {
        $this->info(__("Dependencies Check"));
        $table = new Table($this->output);
        $table->setRows([
            [__('PHP Version'), phpversion()],
            [__('OpenSSL Extension'), phpversion('openssl')],
            [__('PDO Extension'), phpversion('pdo')],
            [__('PDO MySQL Extension'), phpversion('pdo_mysql')],
            [__('mbstring Extension'), phpversion('mbstring')],
            [__('Tokenizer Extension'), phpversion('tokenizer')],
            [__('XML Extension'), phpversion('xml')],
            [__('CType Extension'), phpversion('ctype')],
            [__('JSON Extension'), phpversion('json')],
            [__('GD Extension'), phpversion('gd')],
            [__('SOAP Extension'), phpversion('soap')]
        ]);
        $table->render();
        return true;
    }

    private function fetchDatabaseCredentials()
    {
        $this->env['DB_HOSTNAME'] = $this->anticipate(__("Enter your MySQL host"), ['localhost']);
        $this->env['DB_PORT'] = $this->anticipate(__("Enter your MySQL port (Usually 3306)"), [3306]);
        $this->env['DB_DATABASE'] = $this->anticipate(__("Enter your MySQL Database name"), ['workflow']);
        $this->env['DB_USERNAME'] = $this->ask(__("Enter your MySQL Username"));
        $this->env['DB_PASSWORD'] = $this->secret(__("Enter your MySQL Password (Input hidden)"));
    }

    private function testDatabaseConnection()
    {
        // Setup Laravel Database Configuration
        config(['database.connections.workflow' => [
            'driver' => 'mysql',
            'host' => $this->env['DB_HOSTNAME'],
            'port' => $this->env['DB_PORT'],
            'database' => $this->env['DB_DATABASE'],
            'username' => $this->env['DB_USERNAME'],
            'password' => $this->env['DB_PASSWORD']
        ]]);
        // Attempt to connect
        try {
            $pdo = DB::connection('workflow')->getPdo();
        } catch(Exception $e) {
            $this->error(__("Failed to connect to MySQL database. Check your credentials and try again. Note, the database must also exist."));
            return false;
        }
        return true;
    }
}
