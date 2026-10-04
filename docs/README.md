# Strukt Orm documentation

Managed entities over [Strukt Ar](../ar/docs/README.md): an identity map, a
unit of work, and a result cache layered on the very same `Strukt\Ar\Model`
classes and connection as the active record layer.

Topics:

- [The manager](manager.md) — the mapper hub, connection binding, reading, and persistence.
- [Identity map](identity-map.md) — one managed instance per row.
- [Unit of work](unit-of-work.md) — deferred, batched writes inside one transaction.
- [Repositories](repositories.md) — identity-mapped, result-cached querying.
- [Working with ar](both-layers.md) — how the managed and active record layers coexist.