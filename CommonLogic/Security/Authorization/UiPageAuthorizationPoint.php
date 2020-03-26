<?php
namespace exface\Core\CommonLogic\Security\Authorization;

use exface\Core\Interfaces\Model\UiPageInterface;
use exface\Core\Interfaces\UserImpersonationInterface;
use exface\Core\DataTypes\PolicyEffectDataType;
use exface\Core\Exceptions\Security\AccessDeniedError;
use exface\Core\Events\Security\OnAuthorizedEvent;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\Security\AuthorizationPointInterface;
use exface\Core\Exceptions\Security\AccessPermissionDeniedError;

/**
 * 
 * 
 * @method GenericAuthorizationPolicy[] getPolicies()
 * 
 * @author Andrej Kabachnik
 *
 */
class UiPageAuthorizationPoint extends AbstractAuthorizationPoint
{

    /**
     * 
     * @see \exface\Core\Interfaces\Security\AuthorizationPointInterface::authorize()
     */
    public function authorize(UiPageInterface $page = null, UserImpersonationInterface $userOrToken = null) : UiPageInterface
    {
        if ($userOrToken === null) {
            $userOrToken = $this->getWorkbench()->getSecurity()->getAuthenticatedToken();
        }
        
        $permissionsGenerator = $this->evaluatePolicies($page, $userOrToken);
        $this->combinePermissions($permissionsGenerator, $userOrToken, $page);
        return $page;
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\Security\AuthorizationPointInterface::addPolicy()
     */
    public function addPolicy(array $targets, PolicyEffectDataType $effect, string $name = '', UxonObject $condition = null) : AuthorizationPointInterface
    {
        $this->addPolicyInstance(new GenericAuthorizationPolicy($this->getWorkbench(), $name, $effect, $targets, $condition));
        return $this;
    }
    
    protected function evaluatePolicies(UiPageInterface $page, UserImpersonationInterface $userOrToken) : \Generator
    {
        foreach ($this->getPolicies($userOrToken) as $policy) {
            yield $policy->authorize($userOrToken, $page);
        }
    }
}