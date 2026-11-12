<?php

namespace App\Livewire;

use App\Models\User;
use App\ServiceAvailability;
use App\ServiceCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class Dashboard extends Component
{
    use WithPagination;

    /** @var array<string, string> */
    private const VERIFICATION_ROUTES = [
        'nin-v1' => 'nin.v1',
        'nin-modification' => 'nin.modification',
        'personalization' => 'personalization',
        'nin-v2' => 'nin.v2',
        'nin-v3' => 'nin.v3',
        'bvn-verification' => 'bvn.verify',
        'bvn-modification' => 'bvn.modification',
        'bvn-license-onboarding' => 'bvn.onboarding',
        'ipe-clearing' => 'ipe.clearing',
        'instant-ipe' => 'ipe.instant',
        'mod-ipe' => 'ipe.mod',
        'bvn retrival' => 'bvn.retrieval',
        'validations' => 'validations',
        'delinking' => 'delinking',
    ];

    #[Locked]
    public string $ninVersion = '';

    #[Locked]
    public bool $isBvnVerification = false;

    #[Locked]
    public bool $isBvnModification = false;

    #[Locked]
    public bool $isBvnLicenseOnboarding = false;

    #[Locked]
    public bool $isNinModification = false;

    #[Locked]
    public bool $isPersonalization = false;

    #[Locked]
    public string $modificationStage = '';

    #[Locked]
    public bool $isBvnRetrieval = false;

    #[Locked]
    public bool $isNinValidation = false;

    #[Locked]
    public bool $isDelinking = false;

    #[Locked]
    public bool $showTransactions = false;

    #[Locked]
    public bool $showComplaints = false;

    #[Locked]
    public bool $showTransfer = false;

    #[Locked]
    public string $ipeService = '';

    #[Locked]
    public ?string $selectedServiceId = null;

    public function mount(string $ninVersion = '', bool $isBvnVerification = false, string $ipeService = '', bool $isBvnRetrieval = false, bool $isNinValidation = false, bool $isDelinking = false, bool $showTransactions = false, bool $showTransfer = false, bool $isNinModification = false, string $modificationStage = '', bool $isPersonalization = false, bool $isBvnModification = false, bool $showComplaints = false, bool $isBvnLicenseOnboarding = false): void
    {
        abort_unless(Auth::check(), 403);
        abort_if($isBvnLicenseOnboarding && ($showComplaints || $isBvnModification || $isPersonalization || $isNinModification || $showTransfer || $showTransactions || $ninVersion !== '' || $isBvnVerification || $isBvnRetrieval || $isNinValidation || $isDelinking || $ipeService !== ''), 404);
        $this->isBvnLicenseOnboarding = $isBvnLicenseOnboarding;
        abort_unless(in_array($ninVersion, ['', 'v1', 'v2', 'v3'], true), 404);

        abort_if($isBvnVerification && $ninVersion !== '', 404);

        abort_unless(in_array($ipeService, ['', 'ipe-clearing', 'mod-ipe', 'instant-ipe'], true), 404);
        abort_if($ipeService !== '' && ($ninVersion !== '' || $isBvnVerification), 404);

        abort_if($isBvnRetrieval && ($ninVersion !== '' || $isBvnVerification || $ipeService !== ''), 404);

        abort_if($isNinValidation && ($ninVersion !== '' || $isBvnVerification || $isBvnRetrieval || $ipeService !== ''), 404);

        abort_if($isDelinking && ($ninVersion !== '' || $isBvnVerification || $isBvnRetrieval || $isNinValidation || $ipeService !== ''), 404);

        abort_if($showTransactions && ($ninVersion !== '' || $isBvnVerification || $isBvnRetrieval || $isNinValidation || $isDelinking || $ipeService !== ''), 404);

        if ($showTransfer) {
            Gate::authorize('wallet.transfer');
            abort_if($showTransactions || $ninVersion !== '' || $isBvnVerification || $isBvnRetrieval || $isNinValidation || $isDelinking || $ipeService !== '', 404);
        }
        abort_if($isNinModification && ($showTransfer || $showTransactions || $ninVersion !== '' || $isBvnVerification || $isBvnRetrieval || $isNinValidation || $isDelinking || $ipeService !== ''), 404);
        abort_unless(in_array($modificationStage, ['', 'first', 'second'], true), 404);
        abort_if($modificationStage !== '' && ! $isNinModification, 404);
        $this->modificationStage = $modificationStage;
        abort_if($isPersonalization && ($isNinModification || $showTransfer || $showTransactions || $ninVersion !== '' || $isBvnVerification || $isBvnRetrieval || $isNinValidation || $isDelinking || $ipeService !== ''), 404);
        abort_if($isBvnModification && ($isPersonalization || $isNinModification || $showTransfer || $showTransactions || $ninVersion !== '' || $isBvnVerification || $isBvnRetrieval || $isNinValidation || $isDelinking || $ipeService !== ''), 404);
        abort_if($showComplaints && ($isBvnModification || $isPersonalization || $isNinModification || $showTransfer || $showTransactions || $ninVersion !== '' || $isBvnVerification || $isBvnRetrieval || $isNinValidation || $isDelinking || $ipeService !== ''), 404);
        $this->showComplaints = $showComplaints;
        $this->isBvnModification = $isBvnModification;
        $this->isPersonalization = $isPersonalization;
        $this->isNinModification = $isNinModification;
        $this->showTransfer = $showTransfer;
        $this->showTransactions = $showTransactions;
        $this->isDelinking = $isDelinking;
        $this->isNinValidation = $isNinValidation;
        $this->isBvnRetrieval = $isBvnRetrieval;
        $this->ipeService = $ipeService;
        $this->ninVersion = $ninVersion;
        $this->isBvnVerification = $isBvnVerification;
    }

    public function hydrate(): void
    {
        abort_unless(Auth::check(), 403);
    }

    /** @return array<string, array{name: string, category: string, initials: string}> */
    #[Computed]
    public function services(): array
    {
        return array_diff_key(ServiceCatalog::dashboardServices(), ServiceAvailability::disabledServices());
    }

    /** @return array<string, array{name: string, category: string, initials: string}> */
    #[Computed]
    public function navigationServices(): array
    {
        return array_intersect_key($this->services(), self::VERIFICATION_ROUTES);
    }

    /** @return array{name: string, category: string, initials: string}|null */
    #[Computed]
    public function selectedService(): ?array
    {
        return ServiceCatalog::dashboardServices()[$this->selectedServiceId] ?? null;
    }

    /** @return array<string, bool> */
    #[Computed]
    public function unavailableServices(): array
    {
        return ServiceAvailability::unavailableServices();
    }

    /** @return array<string, string> */
    #[Computed]
    public function verificationRoutes(): array
    {
        return self::VERIFICATION_ROUTES;
    }

    #[Computed]
    public function pageTitle(): string
    {
        if ($this->isBvnLicenseOnboarding) {
            return 'BVN license onboarding';
        }

        if ($this->showComplaints) {
            return 'Complains';
        }

        if ($this->isBvnModification) {
            return 'BVN modification';
        }

        if ($this->isPersonalization) {
            return 'Personalization';
        }

        if ($this->isNinModification) {
            return 'NIN modification';
        }

        if ($this->showTransfer) {
            return 'Wallet transfer';
        }

        if ($this->showTransactions) {
            return 'Transactions';
        }

        if ($this->isDelinking) {
            return 'Self-Service';
        }

        if ($this->isNinValidation) {
            return 'Validations';
        }

        if ($this->ipeService !== '') {
            return ServiceCatalog::dashboardServices()[$this->ipeService]['name'];
        }

        if ($this->isBvnRetrieval) {
            return 'BVN retrieval';
        }

        if ($this->isBvnVerification) {
            return 'BVN verification';
        }

        return $this->ninVersion === '' ? 'Dashboard' : 'NIN-'.strtoupper($this->ninVersion);
    }

    public function selectService(string $serviceId): void
    {
        abort_unless(Auth::check(), 403);
        abort_unless(isset(ServiceCatalog::dashboardServices()[$serviceId]), 404);
        if (isset(self::VERIFICATION_ROUTES[$serviceId])) {
            if (array_key_exists(ServiceAvailability::canonical($serviceId), ServiceCatalog::all()) && ! ServiceAvailability::isEnabled($serviceId)) {
                ServiceAvailability::validateSubmission($serviceId);
            }
            $this->redirectRoute(self::VERIFICATION_ROUTES[$serviceId]);

            return;
        }

        $this->selectedServiceId = $serviceId;
    }

    public function closeService(): void
    {
        $this->selectedServiceId = null;
    }

    public function logout(): void
    {
        abort_unless(Auth::check(), 403);

        Auth::logout();
        session()->invalidate();
        session()->regenerateToken();

        $this->redirectRoute('home');
    }

    public function render(): View
    {
        return view('livewire.dashboard', [
            'ninModificationAvailable' => ! $this->isNinModification || ServiceAvailability::canSubmit('nin-modification'),
            'walletBalance' => (int) User::query()->whereKey(Auth::id())->value('wallet_balance'),
            'transactions' => $this->showTransactions ? Auth::user()->walletTransactions()->latest('id')->paginate(20) : null,
        ]);
    }
}
