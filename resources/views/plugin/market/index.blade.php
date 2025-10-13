<div class="card shadow-sm">
    <div class="card-header bg-primary text-white">
        <h4 class="mb-0">Plugin Market</h4>
    </div>

    <div class="card-body p-0">
        <table class="table table-striped table-hover mb-0">
            <thead class="table-dark">
                <tr>
                    <th>ID</th>
                    <th>Tên hiển thị</th>
                    <th>Tên nội bộ</th>
                    <th>Loại</th>
                    <th>Tác giả</th>
                    <th>Phiên bản mới nhất</th>
                    <th>Changelog</th>
                    <th>Hành động</th>
                </tr>
            </thead>
            <tbody>
                @forelse($plugins as $plugin)
                    <tr>
                        <td>{{ $plugin['id'] ?? '—' }}</td>
                        <td><strong>{{ $plugin['view_name'] ?? $plugin['name'] }}</strong></td>
                        <td><code>{{ $plugin['name'] ?? '—' }}</code></td>
                        <td><span class="badge bg-secondary">{{ $plugin['plugin_type'] ?? '—' }}</span></td>
                        <td>{{ $plugin['author'] ?? '—' }}</td>
                        <td><span class="badge bg-info">{{ $plugin['latest_version'] ?? '—' }}</span></td>
                        <td><small>{{ $plugin['changelog'] ?? '—' }}</small></td>
                        <td>
                            <form action="{{ route('plugin.market.install', $plugin['id']) }}" method="POST"
                                style="display:inline;">
                                @csrf
                                <button type="submit" class="btn btn-success btn-sm">⚡ Install</button>
                            </form>
                            <a href="{{ route('plugin.market.show', $plugin['id']) }}" class="btn btn-primary btn-sm">🔍 Chi
                                tiết</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="text-center text-muted py-3">Không có plugin nào</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>