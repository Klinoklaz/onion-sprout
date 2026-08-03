(() => {
    const rewrite = (oldUrl) => {
        const url = String(oldUrl)
        if (url.startsWith('/')) {
            return MY_SERVER + '/' + ORIGIN + url
        }
        if (!URL.canParse(url)) {
            return oldUrl
        }
        const urlObj = new URL(url)
        if (urlObj.protocol !== 'data:'
            && urlObj.hostname !== new URL(MY_SERVER).hostname) {
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

    if (!'MutationObserver' in globalThis) {
        return
    }
    // rewrite links in dynamic elements
    const hasSrc = ['IMG', 'SCRIPT', 'IFRAME', 'VIDEO', 'AUDIO', 'SOURCE', 'TRACK']
    const hasHref = ['A', 'LINK']
    const observer = new MutationObserver((muList) => {
        for (const mutation of muList) {
            if (mutation.type !== 'childList') {
                continue
            }
            mutation.addedNodes.forEach((node) => {
                if (hasSrc.includes(node.tagName) && node.src) {
                    node.src = rewrite(node.src)
                } else if (hasHref.includes(node.tagName) && node.href) {
                    node.href = rewrite(node.href)
                }
            })
        }
    })
    observer.observe(document.documentElement, {childList: true, subtree: true})
})()