export type PermissionAwareNavigationItem = {
  label: string
  href: string
  requiredPermissions?: string[]
  requiresAllPermissions?: boolean
  children?: PermissionAwareNavigationItem[]
}

export function hasPermission(
  userPermissions: readonly string[],
  requiredPermission: string,
): boolean {
  return userPermissions.includes(requiredPermission)
}

export function filterNavigationByPermissions(
  items: readonly PermissionAwareNavigationItem[],
  userPermissions: readonly string[],
): PermissionAwareNavigationItem[] {
  return items.flatMap((item) => {
    const required = item.requiredPermissions ?? []
    const visible = item.requiresAllPermissions
      ? required.every((permission) => hasPermission(userPermissions, permission))
      : required.length === 0 ||
        required.some((permission) => hasPermission(userPermissions, permission))

    if (!visible) {
      return []
    }

    const children = item.children
      ? filterNavigationByPermissions(item.children, userPermissions)
      : undefined

    if (item.children && children?.length === 0) {
      return []
    }

    return [{ ...item, ...(children ? { children } : {}) }]
  })
}
