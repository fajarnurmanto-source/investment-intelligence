param(
    [ValidateSet('Status', 'Pull', 'Push', 'Sync')]
    [string]$Mode = 'Sync'
)

$ErrorActionPreference = 'Stop'
$repoRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$expectedRemote = 'https://github.com/fajarnurmanto-source/investment-intelligence.git'

function Invoke-Git {
    param([Parameter(ValueFromRemainingArguments = $true)][string[]]$Arguments)
    & git -C $repoRoot @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "git $($Arguments -join ' ') failed with exit code $LASTEXITCODE"
    }
}

try {
    $topLevel = (& git -C $repoRoot rev-parse --show-toplevel).Trim().Replace('/', '\')
    if ($LASTEXITCODE -ne 0 -or $topLevel -ne $repoRoot) {
        throw "Canonical worktree mismatch: $topLevel"
    }

    $remote = (& git -C $repoRoot remote get-url origin).Trim()
    if ($remote -ne $expectedRemote) {
        throw "Unexpected origin remote: $remote"
    }

    $branch = (& git -C $repoRoot branch --show-current).Trim()
    if ([string]::IsNullOrWhiteSpace($branch)) { throw 'Detached HEAD is not supported.' }

    $tracked = @(& git -C $repoRoot ls-files)
    $blocked = @($tracked | Where-Object {
        ($_ -match '(^|/)(\.env|database\.sqlite|.*\.sqlite3?|vendor/|node_modules/|storage/logs/|runtime_data/)') -and
        ($_ -notmatch '(^|/)\.env\.example$') -and
        ($_ -notmatch '(^|/)\.gitignore$')
    })
    if ($blocked.Count -gt 0) {
        throw "Runtime, secret, or installed dependency is tracked: $($blocked -join ', ')"
    }

    Invoke-Git fetch --prune origin

    $dirty = @(& git -C $repoRoot status --porcelain)
    if ($Mode -in @('Pull', 'Push', 'Sync') -and $dirty.Count -gt 0) {
        throw 'Working tree is not clean. Commit or preserve local changes before synchronization.'
    }

    $remoteRef = "refs/remotes/origin/$branch"
    & git -C $repoRoot show-ref --verify --quiet $remoteRef
    $remoteBranchExists = $LASTEXITCODE -eq 0

    if ($Mode -in @('Pull', 'Sync') -and $remoteBranchExists) {
        Invoke-Git pull --ff-only origin $branch
    }

    if ($Mode -in @('Push', 'Sync')) {
        if ($branch -in @('main', 'master', 'development')) {
            throw "Direct push to protected branch '$branch' is blocked."
        }
        Invoke-Git push --set-upstream origin $branch
    }

    $head = (& git -C $repoRoot rev-parse HEAD).Trim()
    & git -C $repoRoot show-ref --verify --quiet "refs/remotes/origin/$branch"
    $remoteHeadExists = $LASTEXITCODE -eq 0
    $remoteHead = if ($remoteHeadExists) {
        (& git -C $repoRoot rev-parse "origin/$branch").Trim()
    } else { 'NOT_PUBLISHED' }

    Write-Output "II_SYNC=PASS"
    Write-Output "MODE=$Mode"
    Write-Output "BRANCH=$branch"
    Write-Output "LOCAL_HEAD=$head"
    Write-Output "REMOTE_HEAD=$remoteHead"
} catch {
    Write-Output "II_SYNC=BLOCKED"
    Write-Output "REASON=$($_.Exception.Message)"
    exit 1
}
