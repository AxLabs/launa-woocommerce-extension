<?php
declare(strict_types=1);

defined('ABSPATH') || exit;


/**
 * Tries providers in order; first non-null rate wins.
 */
final class Launa_WC_Composite_Exchange_Rates implements Launa_WC_Exchange_Rate_Provider
{
    /** @var list<Launa_WC_Exchange_Rate_Provider> */
    private array $providers;

    /**
     * @param list<Launa_WC_Exchange_Rate_Provider> $providers
     */
    public function __construct(array $providers)
    {
        $this->providers = $providers;
    }

    /**
     * Stablecoins stay 1:1; market tokens use the control-plane FX API when a client is available.
     */
    public static function default(
        ?Launa_WC_Control_Plane_Client $client = null,
        bool $force_refresh_fx = false
    ): self {
        $providers = [new Launa_WC_Stablecoin_One_To_One_Rates()];

        if ($client === null && function_exists('get_option')) {
            $client = Launa_WC_Settings::client();
        }

        if ($client !== null) {
            $providers[] = new Launa_WC_Control_Plane_Exchange_Rates($client, $force_refresh_fx);
        } else {
            $providers[] = new Launa_WC_Stub_Market_Rates();
        }

        return new self($providers);
    }

    public function rate_usd_to_token(string $symbol, string $network): ?string
    {
        foreach ($this->providers as $provider) {
            $rate = $provider->rate_usd_to_token($symbol, $network);
            if ($rate !== null && $rate !== '') {
                return $rate;
            }
        }

        return null;
    }

    /**
     * Date of the control-plane FX snapshot, if that provider was used.
     */
    public function last_rate_date(): string
    {
        foreach ($this->providers as $provider) {
            if ($provider instanceof Launa_WC_Control_Plane_Exchange_Rates) {
                $date = $provider->last_rate_date();
                if ($date !== '') {
                    return $date;
                }
            }
        }

        return '';
    }
}
