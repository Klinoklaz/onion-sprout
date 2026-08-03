(() => {
    const rewrite = (oldUrl) => {
        const url = String(oldUrl)
        if (url.startsWith('/')) {
            return MY_SERVER + '/' + ORIGIN + url
        }
        if (URL.canParse(url)
            && new URL(url).hostname !== new URL(MY_SERVER).hostname) {
            return MY_SERVER + '/' + url
        }
        return oldUrl
    }

    const realFetch = window.fetch
    window.fetch = async function (resource, options) {
        return await realFetch(rewrite(resource), options)
    }

    const realOpen = XMLHttpRequest.prototype.open
    XMLHttpRequest.prototype.open = function(method, url, ...args) {
        url = rewrite(url)
        return realOpen.apply(this, arguments)
    }
})()