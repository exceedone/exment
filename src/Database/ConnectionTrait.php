<?php

namespace Exceedone\Exment\Database;

use Closure;
use Throwable;

trait ConnectionTrait
{
    /**
     * Get a new query builder instance.
     *
     * @return \Exceedone\Exment\Database\Query\ExtendedBuilder
     */
    public function query()
    {
        return new \Exceedone\Exment\Database\Query\ExtendedBuilder(
            $this,
            $this->getQueryGrammar(),
            $this->getPostProcessor()
        );
    }

    /**
     * Get database version.
     *
     * @return void
     */
    public function getVersion()
    {
        return $this->getSchemaBuilder()->getVersion();
    }

    /**
     * Check mariadb
     *
     * @return bool
     */
    public function isMariaDB()
    {
        return $this->getSchemaBuilder()->isMariaDB();
    }

    /**
     * Check postgresql
     *
     * @return bool
     */
    public function isPostgres()
    {
        return false;
    }

    /**
     * Check sqlserver
     *
     * @return bool
     */
    public function isSqlServer()
    {
        return false;
    }

    /**
     * Check whether casting column compare
     *
     * @return bool
     */
    public function isCastColumnCompare() : bool
    {
        return $this->getSchemaBuilder()->isCastColumnCompare();
    }

    public function canConnection()
    {
        try {
            $this->getSchemaBuilder()->getVersion();
            return true;
        } catch (\Exception $ex) {
            return false;
        }
    }


    /**
     * Execute a Closure within a transaction.
     * *PHP8 checks transactions, and if already closed transaction, throw exception. So we need other functions.
     *
     * *COPIED from Illuminate\Database\Concerns\ManagesTransactions*
     *
     * @param  \Closure  $callback
     * @param  int  $attempts
     * @return mixed
     *
     * @throws \Throwable
     */
    public function transaction(Closure $callback, $attempts = 1)
    {
        for ($currentAttempt = 1; $currentAttempt <= $attempts; $currentAttempt++) {
            $this->beginTransaction();

            // We'll simply execute the given callback within a try / catch block and if we
            // catch any exception we can rollback this transaction so that none of this
            // gets actually persisted to a database or stored in a permanent fashion.
            try {
                $callbackResult = $callback($this);
            }

            // If we catch an exception we'll rollback this transaction and try again if we
            // are not out of attempts. If we are out of attempts we will just throw the
            // exception back out and let the developer handle an uncaught exceptions.
            catch (Throwable $e) {
                $this->handleTransactionException(
                    $e,
                    $currentAttempt,
                    $attempts
                );

                continue;
            }

            try {
                //CUSTOMIZED.
                // If not already transaction, re-set pdo.
                if (!($this->getPdo()->inTransaction())) {
                    $this->setPdo($this->getPdo());
                }

                if ($this->transactions == 1) {
                    $this->getPdo()->commit();
                }

                $this->transactions = max(0, $this->transactions - 1);

                if ($this->transactions == 0) {
                    optional($this->transactionsManager)->commit($this->getName());
                }
            } catch (Throwable $e) {
                $this->handleCommitTransactionException(
                    $e,
                    $currentAttempt,
                    $attempts
                );

                continue;
            }

            $this->fireConnectionEvent('committed');

            return $callbackResult;
        }
    }
}
