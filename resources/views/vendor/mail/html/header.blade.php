@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
<img src="{{ rtrim((string) config('app.url'), '/') }}/socialmesh.png" class="logo" alt="{{ config('app.name', 'SocialMesh') }} Logo">
</a>
</td>
</tr>
